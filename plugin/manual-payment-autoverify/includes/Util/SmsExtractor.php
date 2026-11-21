<?php
/**
 * Universal payment-SMS extractor.
 *
 * Pulls the credited amount and the transaction ID out of a payment SMS of
 * ANY format (wallet or bank). Provider parsers call this when their strict
 * regexes do not match.
 *
 * Algorithm
 * ---------
 * 1. TID  : keyword match (Trx ID, TID, Txn No, Ref #, RRN, UTR ...), ranked by
 *           keyword priority. Token must contain a digit. If no keyword is
 *           found, fall back to a long alphanumeric token that is not a phone
 *           number, date, masked account or IBAN.
 * 2. Amount: every PKR amount candidate ("Rs. 1,500", "1500 PKR", "amount: 1500")
 *           is scored. Balance / limit / due context = heavily penalised,
 *           fee / tax context = penalised, credit keywords nearby = boosted.
 *           Best score wins. Candidates overlapping the TID token are dropped.
 * 3. Direction: only CREDIT (money received) messages are accepted. A debit
 *           SMS must never verify a customer's payment claim.
 *
 * Money is returned as integer minor units (paisa) via Money::from_decimal().
 * Non-PKR currencies are ignored so "$500" can never match "Rs 500".
 *
 * ReDoS defence: input capped at 1024 bytes, possessive quantifiers on
 * separator runs, bounded token lengths.
 *
 * @package ManualPaymentAutoVerify\Util
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Util;

final class SmsExtractor {

	private const MAX_BODY_LENGTH = 1024;

	private const CUR = '(?:PKR|Rs|Rupees?|INR|USD|AED|SAR|GBP|EUR|\$|₹|£|€)';
	private const NUM = '(\d{1,3}(?:,\d{2,3})+(?:\.\d+)?|\d+(?:\.\d+)?)';

	private const RE_BALANCE = '/(?:bal(?:ance)?|avl|avail(?:able)?|limit|remaining|outstanding|due)\W*(?:\w+\W+){0,5}$/i';
	private const RE_FEE     = '/(?:fees?|charges?|tax|fed|wht|commission|levy|gst|cess)\W*(?:\w+\W+){0,3}$/i';
	private const RE_POSITIVE = '/(?:debit|credit|receiv|deposit|transfer|payment|paid|amount|amt|txn|trx)/i';

	private const RE_CREDIT = '/\b(?:received|credited|deposited|incoming)\b|\badded\s+to\b|\btransferred\s+to\s+your\b/i';
	private const RE_DEBIT  = '/\b(?:debited|withdrawn|withdrawal|deducted|spent|purchase)\b|\byou(?:\s+have)?\s+(?:sent|paid|transferred|made)\b|\bpaid\s+to\b|\bsent\s+to\s+(?!your\b)/i';

	/**
	 * Extracts payment details from an SMS body.
	 *
	 * @param string $body Raw SMS text.
	 *
	 * @return array{amount_minor:int, amount_raw:string, tid:string, tid_source:string, others:int[]}|null
	 *         Null when the SMS is not a recognisable credit with amount + TID.
	 */
	public static function extract( string $body ): ?array {
		$body = str_replace( "\xC2\xA0", ' ', $body ); // Non-breaking space.

		if ( '' === trim( $body ) || strlen( $body ) > self::MAX_BODY_LENGTH ) {
			return null;
		}

		// 1. Direction: must be an incoming payment.
		if ( ! self::is_credit( $body ) ) {
			return null;
		}

		// 2. Transaction ID (keyword first, so its span can be excluded from amounts).
		$tid_kw = self::find_keyword_tid( $body );

		// 3. Amount.
		$exclude = null !== $tid_kw ? array( array( $tid_kw['start'], $tid_kw['end'] ) ) : array();
		$amounts = self::find_amounts( $body, $exclude );
		if ( empty( $amounts ) ) {
			return null;
		}
		$best = $amounts[0];

		// 4. TID fallback (needs amount spans to avoid picking an amount).
		$tid        = null;
		$tid_source = 'keyword';
		if ( null !== $tid_kw ) {
			$tid = $tid_kw['tid'];
		} else {
			$spans = array();
			foreach ( $amounts as $a ) {
				$spans[] = array( $a['start'], $a['end'] );
			}
			$tid        = self::find_fallback_tid( $body, $spans );
			$tid_source = 'fallback';
		}

		if ( null === $tid || ! TidNormalizer::is_valid( $tid ) ) {
			return null;
		}

		try {
			$minor = Money::from_decimal( $best['raw'] );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
		if ( $minor <= 0 ) {
			return null;
		}

		$others = array();
		foreach ( array_slice( $amounts, 1 ) as $a ) {
			try {
				$others[] = Money::from_decimal( $a['raw'] );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		return array(
			'amount_minor' => $minor,
			'amount_raw'   => str_replace( ',', '', $best['raw'] ),
			'tid'          => TidNormalizer::normalize( $tid ),
			'tid_source'   => $tid_source,
			'others'       => $others,
		);
	}

	/**
	 * True when the first direction keyword in the SMS says money came IN.
	 */
	public static function is_credit( string $body ): bool {
		if ( ! preg_match( self::RE_CREDIT, $body, $c, PREG_OFFSET_CAPTURE ) ) {
			return false;
		}
		if ( preg_match( self::RE_DEBIT, $body, $d, PREG_OFFSET_CAPTURE ) && $d[0][1] < $c[0][1] ) {
			return false;
		}
		return true;
	}

	// ------------------------------------------------------------------ //
	// Amount
	// ------------------------------------------------------------------ //

	/**
	 * @param string            $body    SMS body.
	 * @param array<int,int[]>  $exclude [start,end] byte spans that must not count as amounts.
	 *
	 * @return array<int, array{raw:string,start:int,end:int,score:float}> Sorted best-first.
	 */
	private static function find_amounts( string $body, array $exclude ): array {
		$cands = array();

		$overlaps = static function ( int $s, int $e, array $spans ): bool {
			foreach ( $spans as $sp ) {
				if ( $s < $sp[1] && $e > $sp[0] ) {
					return true;
				}
			}
			return false;
		};

		// Pattern A: currency first ("Rs. 1,500.00", "PKR1500").
		$re_a = '/(?<![A-Za-z])(' . self::CUR . ')\.?\s*[:\-]?\s*' . self::NUM . '/iu';
		if ( preg_match_all( $re_a, $body, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $x ) {
				$s = $x[0][1];
				$e = $s + strlen( $x[0][0] );
				if ( ! self::is_pkr( $x[1][0] ) || $overlaps( $s, $e, $exclude ) ) {
					continue;
				}
				$cands[] = array( 'raw' => $x[2][0], 'start' => $s, 'end' => $e, 'cur' => true );
			}
		}

		// Pattern B: number first ("1500 PKR", "500 Rs").
		$re_b = '/(?<![\d,.])' . self::NUM . '\s*(' . self::CUR . ')(?![A-Za-z])/iu';
		if ( preg_match_all( $re_b, $body, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $x ) {
				$s = $x[0][1];
				$e = $s + strlen( $x[0][0] );
				if ( ! self::is_pkr( $x[2][0] ) || $overlaps( $s, $e, $exclude ) || $overlaps( $s, $e, array_map( static fn( $c ) => array( $c['start'], $c['end'] ), $cands ) ) ) {
					continue;
				}
				$raw = $x[1][0];
				// A long bare digit run before "Rs" is a TID, not an amount.
				if ( ctype_digit( $raw ) && strlen( $raw ) >= 9 ) {
					continue;
				}
				// Number right after an ID keyword is a TID.
				$before = substr( $body, max( 0, $s - 20 ), min( 20, $s ) );
				if ( preg_match( '/(?:id|no|num|number|#|ref|tid|trx|txn)\W*$/i', $before ) ) {
					continue;
				}
				$cands[] = array( 'raw' => $raw, 'start' => $s, 'end' => $e, 'cur' => true );
			}
		}

		// Pattern C: keyword + bare number, only when there is no currency symbol at all.
		if ( empty( $cands ) ) {
			$re_c = '/(?:amount|amt|received|credited|deposited)\s*(?:of|is|:|for)?\s*' . self::NUM . '/iu';
			if ( preg_match_all( $re_c, $body, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				foreach ( $m as $x ) {
					$s = $x[0][1];
					$e = $s + strlen( $x[0][0] );
					if ( preg_match( '/^0\d{9,}$/', $x[1][0] ) || $overlaps( $s, $e, $exclude ) ) {
						continue; // Phone number.
					}
					$cands[] = array( 'raw' => $x[1][0], 'start' => $s, 'end' => $e, 'cur' => false );
				}
			}
		}

		// Score.
		$scored = array();
		foreach ( $cands as $c ) {
			$value = (float) str_replace( ',', '', $c['raw'] ); // Used only for the >0 check, never for money.
			if ( $value <= 0 ) {
				continue;
			}

			$pre    = substr( $body, max( 0, $c['start'] - 45 ), min( 45, $c['start'] ) );
			$pre_ar = preg_split( '/[\n;]/', $pre );
			$before = is_array( $pre_ar ) ? (string) end( $pre_ar ) : $pre;
			$after  = substr( $body, $c['end'], 30 );

			$score = 0.0;
			if ( preg_match( self::RE_BALANCE, $before ) ) {
				$score -= 100;
			}
			if ( preg_match( self::RE_FEE, $before ) ) {
				$score -= 30;
			}
			if ( preg_match( self::RE_POSITIVE, $before ) || preg_match( self::RE_POSITIVE, $after ) ) {
				$score += 20;
			}
			if ( $c['cur'] ) {
				$score += 5;
			}
			$score -= $c['start'] / 10000; // Tie-break: earlier amount wins.

			$c['score'] = $score;
			$scored[]   = $c;
		}

		usort(
			$scored,
			static fn( $a, $b ) => $b['score'] <=> $a['score']
		);

		// A candidate that is clearly a balance/limit must never be chosen on its own.
		if ( ! empty( $scored ) && $scored[0]['score'] < -50 ) {
			return array();
		}

		return $scored;
	}

	private static function is_pkr( string $cur ): bool {
		$cur = strtolower( rtrim( $cur, '.' ) );
		return 'pkr' === $cur || 0 === strpos( $cur, 'rs' ) || 0 === strpos( $cur, 'rupee' );
	}

	// ------------------------------------------------------------------ //
	// Transaction ID
	// ------------------------------------------------------------------ //

	/**
	 * Keyword-anchored TID search.
	 *
	 * @return array{tid:string,start:int,end:int}|null
	 */
	private static function find_keyword_tid( string $body ): ?array {
		$re = '/(?<![A-Za-z])(trxid|txnid|txid|trx|txn|tx|transaction|trans|tid|reference|ref|rrn|utr|stan|receipt|rcpt|trace|confirmation|conf|auth)(?![A-Za-z])'
			. '[\s._\-]*+(?:(?:id|no|num|number|code)(?![A-Za-z])[\s.:#\-=]*+){0,2}'
			. '[\s.:#\-=]*+(?:is\s+)?([A-Za-z0-9][A-Za-z0-9\-]{5,39})/i';

		if ( ! preg_match_all( $re, $body, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$found = array();
		foreach ( $m as $x ) {
			$tok = rtrim( $x[2][0], '-' );
			if ( strlen( $tok ) < 6 || ! preg_match( '/\d/', $tok ) ) {
				continue; // Words like "successful" are not IDs.
			}
			$kw = strtolower( $x[1][0] );
			if ( preg_match( '/^(?:trx|txn|tx|trans|transaction|tid)/', $kw ) ) {
				$pr = 3;
			} elseif ( preg_match( '/^(?:ref|reference|utr|rrn|receipt|confirmation)/', $kw ) ) {
				$pr = 2;
			} else {
				$pr = 1;
			}
			$found[] = array(
				'tid'   => $tok,
				'start' => $x[2][1],
				'end'   => $x[2][1] + strlen( $tok ),
				'pr'    => $pr,
				'idx'   => $x[0][1],
			);
		}

		if ( empty( $found ) ) {
			return null;
		}

		usort(
			$found,
			static fn( $a, $b ) => ( $b['pr'] <=> $a['pr'] ) ?: ( $a['idx'] <=> $b['idx'] )
		);

		return array(
			'tid'   => $found[0]['tid'],
			'start' => $found[0]['start'],
			'end'   => $found[0]['end'],
		);
	}

	/**
	 * Keyword-less guess: first long alphanumeric token that is not a phone,
	 * date, masked account, IBAN or part of an amount.
	 *
	 * @param array<int,int[]> $amount_spans Amount [start,end] spans.
	 */
	private static function find_fallback_tid( string $body, array $amount_spans ): ?string {
		if ( ! preg_match_all( '/(?<![A-Za-z0-9*])[A-Za-z0-9]{8,30}(?![A-Za-z0-9*])/', $body, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		foreach ( $m as $x ) {
			$tok = $x[0][0];
			$pos = $x[0][1];

			if ( ! preg_match( '/\d/', $tok ) ) {
				continue;
			}
			foreach ( $amount_spans as $sp ) {
				if ( $pos >= $sp[0] && $pos < $sp[1] ) {
					continue 2;
				}
			}
			if ( preg_match( '/^(?:03\d{9}|923\d{9}|0092\d{10})$/', $tok ) ) {
				continue; // Phone number.
			}
			if ( preg_match( '/^(?:19|20)\d{6}$/', $tok ) ) {
				continue; // Date yyyymmdd.
			}
			if ( preg_match( '/^[xX]+\d+$/', $tok ) ) {
				continue; // Masked account.
			}
			if ( preg_match( '/^[A-Za-z]{2}\d{2}[A-Za-z]{4}\d{10,}$/', $tok ) ) {
				continue; // IBAN.
			}
			return $tok;
		}

		return null;
	}
}
