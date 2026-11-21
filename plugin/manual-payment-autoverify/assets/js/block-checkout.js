/**
 * MPAV Block Checkout — React Payment Method Component
 *
 * Source file: assets/js/checkout/index.js
 * Compiled to: assets/js/block-checkout.js  (npm run build)
 *
 * Registers the MPAV payment method with the WooCommerce Blocks checkout API.
 * Renders an amount banner, provider selection, the account to pay (number, copy,
 * QR, instructions), and the TID + sender-number inputs.
 * Payments are verified from the forwarded SMS, so no screenshot is collected.
 *
 * Data is passed to the server via onPaymentSetup's paymentMethodData object,
 * which Plugin::register_blocks_payment_data_hook() merges into $_POST before
 * MpavGateway::process_payment() runs. Field names are unchanged.
 *
 * @package ManualPaymentAutoVerify
 */

/* global wc, wp */
const { registerPaymentMethod }  = wc.wcBlocksRegistry;
const { getSetting }             = wc.wcSettings;
const { createElement, useState, useEffect } = wp.element;
const { decodeEntities }         = wp.htmlEntities;

// Settings are populated by MpavBlocksIntegration::get_payment_method_data().
const settings = getSetting( 'mpav_data', {} );
const i18n     = settings.i18n || {};
const accounts = settings.accounts || [];

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Validates a TID string: non-empty, letters/numbers/hyphens only.
 * Mirrors the PHP TidNormalizer::is_valid() logic.
 *
 * @param {string} tid
 * @returns {boolean}
 */
function isValidTid( tid ) {
    if ( ! tid || ! tid.trim() ) return false;
    return /^[A-Z0-9\-]{1,128}$/i.test( tid.trim().replace( /\s+/g, '' ) );
}

/**
 * Validates a sender phone number: 10–15 digit characters.
 *
 * @param {string} phone
 * @returns {boolean}
 */
function isValidPhone( phone ) {
    const digits = ( phone || '' ).replace( /\D/g, '' );
    return digits.length >= 10 && digits.length <= 15;
}

/**
 * Formats the cart total for the "Amount to send" banner (display only).
 * Returns '' when the Blocks `billing` prop does not carry usable data.
 *
 * @param {Object} billing WooCommerce Blocks `billing` prop.
 * @returns {string}
 */
function formatTotal( billing ) {
    try {
        const currency = billing && billing.currency;
        const value    = billing && billing.cartTotal && billing.cartTotal.value;
        if ( ! currency || typeof value !== 'number' ) return '';

        const minor  = Number( currency.minorUnit === undefined ? 2 : currency.minorUnit );
        const amount = ( value / Math.pow( 10, minor ) ).toLocaleString( undefined, {
            minimumFractionDigits: minor,
            maximumFractionDigits: minor,
        } );

        return decodeEntities( ( currency.prefix || '' ) + amount + ( currency.suffix || '' ) ).trim();
    } catch ( e ) {
        return '';
    }
}

// ─── Sub-components ───────────────────────────────────────────────────────────

/**
 * Account detail panel (provider chip + number + copy button + QR + instructions).
 */
function AccountDetail( { account, visible } ) {
    const [ copied, setCopied ] = useState( false );

    function handleCopy( e ) {
        e.preventDefault();
        const text = account.accountNumber;
        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            navigator.clipboard.writeText( text ).then( () => {
                setCopied( true );
                setTimeout( () => setCopied( false ), 2000 );
            } );
        }
    }

    // Visibility is class-driven (CSS hides panels without the --active modifier);
    // the DOM stays stable so the ARIA live region works.
    return createElement(
        'div',
        {
            className:          'mpav-account-details' + ( visible ? ' mpav-account-details--active' : '' ),
            'data-provider':    account.provider,
            role:               'region',
            'aria-label':       account.providerLabel,
            'aria-live':        'polite',
        },

        // Header: provider chip + display name.
        createElement(
            'div',
            { className: 'mpav-account-details__head' },
            createElement( 'span', { className: 'mpav-chip' }, decodeEntities( account.providerLabel ) ),
            createElement( 'span', { className: 'mpav-account-details__name' }, decodeEntities( account.displayName ) )
        ),

        // Account number row.
        createElement(
            'div',
            { className: 'mpav-account-info' },
            createElement( 'span', { className: 'mpav-account-info__label' },
                decodeEntities( i18n.sendTo || 'Send to:' )
            ),
            createElement( 'strong', {
                className: 'mpav-account-info__number',
                id:        'mpav-acct-' + account.provider,
            }, decodeEntities( account.accountNumber ) ),
            createElement(
                'button',
                {
                    type:         'button',
                    className:    'mpav-copy-btn' + ( copied ? ' mpav-copy-btn--done' : '' ),
                    onClick:      handleCopy,
                    'aria-label': ( i18n.copy || 'Copy' ) + ' ' + account.accountNumber,
                },
                copied
                    ? decodeEntities( i18n.copied || 'Copied!' )
                    : decodeEntities( i18n.copy   || 'Copy' )
            )
        ),

        // QR image (if provided).
        account.qrUrl
            ? createElement(
                'div',
                { className: 'mpav-qr-wrap' },
                createElement( 'img', {
                    src:     account.qrUrl,
                    alt:     account.providerLabel + ' QR code',
                    width:   200,
                    height:  200,
                    loading: 'lazy',
                    className: 'mpav-qr-image',
                } ),
                createElement( 'span', { className: 'mpav-qr-caption' },
                    decodeEntities( i18n.scanQr || 'Or scan the QR code to pay' )
                )
              )
            : null,

        // Instructions HTML (passed through wp_kses_post on the server).
        account.instructions
            ? createElement( 'div', {
                className:           'mpav-instructions',
                dangerouslySetInnerHTML: { __html: account.instructions },
              } )
            : null
    );
}

// ─── Main payment method component ───────────────────────────────────────────

/**
 * MpavPaymentMethod
 *
 * @param {Object} props WooCommerce Blocks payment method props.
 * @param {Object} props.eventRegistration   { onPaymentSetup }
 * @param {Object} props.emitResponse        { responseTypes }
 * @param {Object} props.billing             { cartTotal, currency } (display only)
 */
function MpavPaymentMethod( { eventRegistration, emitResponse, billing } ) {
    const { onPaymentSetup } = eventRegistration;

    // Form state.
    const [ provider,      setProvider ]      = useState( accounts[0]?.provider || '' );
    const [ tid,           setTid ]           = useState( '' );
    const [ senderNumber,  setSenderNumber ]  = useState( '' );
    const [ providerError, setProviderError ] = useState( '' );
    const [ tidError,      setTidError ]      = useState( '' );
    const [ senderError,   setSenderError ]   = useState( '' );

    // Register payment setup handler.
    useEffect( () => {
        const unsubscribe = onPaymentSetup( () => {
            let valid = true;

            // Validate provider.
            if ( ! provider ) {
                setProviderError( i18n.errProvider || 'Please select a payment method.' );
                valid = false;
            } else {
                setProviderError( '' );
            }

            // Validate TID.
            if ( ! tid.trim() ) {
                setTidError( i18n.errTid || 'Please enter your Transaction ID.' );
                valid = false;
            } else if ( ! isValidTid( tid ) ) {
                setTidError( i18n.errTidInvalid || 'Transaction ID contains invalid characters. Use letters and numbers only.' );
                valid = false;
            } else {
                setTidError( '' );
            }

            // Validate sender number.
            if ( ! senderNumber.trim() ) {
                setSenderError( i18n.errSender || 'Please enter the mobile number you sent payment from.' );
                valid = false;
            } else if ( ! isValidPhone( senderNumber ) ) {
                setSenderError( i18n.errSenderInvalid || 'Please enter a valid mobile number (10–15 digits).' );
                valid = false;
            } else {
                setSenderError( '' );
            }

            if ( ! valid ) {
                return { type: emitResponse.responseTypes.ERROR };
            }

            return {
                type: emitResponse.responseTypes.SUCCESS,
                meta: {
                    paymentMethodData: {
                        mpav_provider:      provider,
                        mpav_tid:           tid.trim(),
                        mpav_sender_number: senderNumber.trim(),
                    },
                },
            };
        } );

        return unsubscribe;
    }, [ onPaymentSetup, provider, tid, senderNumber ] );

    // ── Render ──────────────────────────────────────────────────────────────

    const totalText = formatTotal( billing );

    return createElement(
        'div',
        { className: 'mpav-payment-fields' },

        // Description.
        settings.description
            ? createElement( 'p', { className: 'mpav-description' },
                decodeEntities( settings.description )
              )
            : null,

        // No accounts configured.
        accounts.length === 0
            ? createElement( 'p', { className: 'mpav-no-accounts' },
                'No payment accounts are currently configured. Please contact the store owner.'
              )
            : null,

        // Amount banner.
        accounts.length > 0 && totalText
            ? createElement(
                'div',
                { className: 'mpav-amount', role: 'note' },
                createElement( 'span', { className: 'mpav-amount__label' },
                    decodeEntities( i18n.amountToSend || 'Amount to send' )
                ),
                createElement( 'strong', { className: 'mpav-amount__value' }, totalText ),
                createElement( 'span', { className: 'mpav-amount__hint' },
                    decodeEntities( i18n.amountHint || 'Send exactly this amount so your payment can be verified automatically.' )
                )
              )
            : null,

        accounts.length > 0
            ? createElement(
                'div',
                { className: 'mpav-steps' },

                // Step: choose provider (skipped when only one account exists).
                accounts.length > 1
                    ? createElement(
                        'section',
                        { className: 'mpav-step mpav-field mpav-field--provider' },

                        createElement( 'span', { className: 'mpav-step__title mpav-label', id: 'mpav-blocks-provider-label' },
                            decodeEntities( i18n.chooseProvider || 'Choose payment method' ),
                            createElement( 'abbr', {
                                className:  'required',
                                title:      i18n.required || 'required',
                                'aria-hidden': 'true',
                            }, ' *' )
                        ),

                        createElement(
                            'div',
                            { className: 'mpav-provider-tabs', role: 'radiogroup', 'aria-labelledby': 'mpav-blocks-provider-label' },
                            accounts.map( ( account, idx ) =>
                                createElement(
                                    'label',
                                    {
                                        key:              account.provider,
                                        className:        'mpav-account-card' + ( provider === account.provider ? ' mpav-account-card--active' : '' ),
                                        'data-provider':  account.provider,
                                        htmlFor:          'mpav-blocks-provider-' + idx,
                                    },
                                    createElement( 'input', {
                                        type:      'radio',
                                        id:        'mpav-blocks-provider-' + idx,
                                        name:      'mpav_blocks_provider',
                                        value:     account.provider,
                                        checked:   provider === account.provider,
                                        onChange:  () => setProvider( account.provider ),
                                        className: 'mpav-provider-radio screen-reader-text',
                                    } ),
                                    createElement(
                                        'span',
                                        { className: 'mpav-account-card__text' },
                                        createElement( 'span', { className: 'mpav-account-card__label' },
                                            decodeEntities( account.providerLabel )
                                        ),
                                        createElement( 'span', { className: 'mpav-account-card__name' },
                                            decodeEntities( account.displayName )
                                        )
                                    )
                                )
                            )
                        ),

                        providerError
                            ? createElement( 'span', { className: 'mpav-field-error', role: 'alert' }, providerError )
                            : null
                      )
                    : null,

                // Step: send payment (account panels).
                createElement(
                    'section',
                    { className: 'mpav-step mpav-step--send' },
                    createElement( 'span', { className: 'mpav-step__title' },
                        decodeEntities( i18n.sendPayment || 'Send your payment' )
                    ),
                    accounts.map( ( account ) =>
                        createElement( AccountDetail, {
                            key:     account.provider,
                            account,
                            visible: account.provider === provider,
                        } )
                    )
                ),

                // Step: confirm payment (TID + sender).
                createElement(
                    'section',
                    { className: 'mpav-step mpav-step--confirm' },
                    createElement( 'span', { className: 'mpav-step__title' },
                        decodeEntities( i18n.confirmPayment || 'Confirm your payment' )
                    ),

                    // TID field.
                    createElement(
                        'div',
                        { className: 'mpav-field mpav-field--tid' },
                        createElement( 'label', { htmlFor: 'mpav-blocks-tid', className: 'mpav-label' },
                            decodeEntities( i18n.transactionId || 'Transaction ID (TID)' ),
                            createElement( 'abbr', { className: 'required', title: i18n.required || 'required', 'aria-hidden': 'true' }, ' *' )
                        ),
                        createElement( 'input', {
                            type:          'text',
                            id:            'mpav-blocks-tid',
                            className:     'input-text mpav-input' + ( tidError ? ' mpav-input--invalid' : '' ),
                            value:         tid,
                            onChange:      ( e ) => setTid( e.target.value ),
                            placeholder:   i18n.tidPlaceholder || 'e.g. 123456789',
                            autoComplete:  'off',
                            autoCorrect:   'off',
                            autoCapitalize:'characters',
                            spellCheck:    false,
                            maxLength:     128,
                            'aria-required': 'true',
                            'aria-describedby': tidError ? 'mpav-blocks-tid-error' : 'mpav-blocks-tid-hint',
                            'aria-invalid': tidError ? 'true' : 'false',
                        } ),
                        tidError
                            ? createElement( 'span', { id: 'mpav-blocks-tid-error', className: 'mpav-field-error', role: 'alert' }, tidError )
                            : createElement( 'span', { id: 'mpav-blocks-tid-hint', className: 'mpav-field-hint' },
                                decodeEntities( i18n.tidHint || 'The transaction or reference number from your payment confirmation message.' )
                              )
                    ),

                    // Sender number field.
                    createElement(
                        'div',
                        { className: 'mpav-field mpav-field--sender' },
                        createElement( 'label', { htmlFor: 'mpav-blocks-sender', className: 'mpav-label' },
                            decodeEntities( i18n.senderNumber || 'Sender Mobile Number' ),
                            createElement( 'abbr', { className: 'required', title: i18n.required || 'required', 'aria-hidden': 'true' }, ' *' )
                        ),
                        createElement( 'input', {
                            type:         'tel',
                            id:           'mpav-blocks-sender',
                            className:    'input-text mpav-input' + ( senderError ? ' mpav-input--invalid' : '' ),
                            value:        senderNumber,
                            onChange:     ( e ) => setSenderNumber( e.target.value ),
                            placeholder:  i18n.senderPlaceholder || 'e.g. 03001234567',
                            autoComplete: 'tel',
                            maxLength:    20,
                            inputMode:    'tel',
                            pattern:      '[0-9+\\-\\s]{10,20}',
                            'aria-required': 'true',
                            'aria-describedby': senderError ? 'mpav-blocks-sender-error' : 'mpav-blocks-sender-hint',
                            'aria-invalid': senderError ? 'true' : 'false',
                        } ),
                        senderError
                            ? createElement( 'span', { id: 'mpav-blocks-sender-error', className: 'mpav-field-error', role: 'alert' }, senderError )
                            : createElement( 'span', { id: 'mpav-blocks-sender-hint', className: 'mpav-field-hint' },
                                decodeEntities( i18n.senderHint || 'The mobile number you sent the payment from.' )
                              )
                    ),

                    createElement( 'p', { className: 'mpav-note' },
                        decodeEntities( i18n.autoConfirmNote || 'Your order is confirmed automatically as soon as we receive your payment confirmation.' )
                    )
                )
              )
            : null
    );
}

// ─── Label component ─────────────────────────────────────────────────────────

function MpavLabel() {
    // Small mobile-payment icon (inline SVG, inherits colour) + the gateway title.
    const icon = createElement(
        'span',
        { className: 'mpav-method-icon', 'aria-hidden': 'true' },
        createElement(
            'svg',
            {
                xmlns:          'http://www.w3.org/2000/svg',
                viewBox:        '0 0 24 24',
                width:          22,
                height:         22,
                fill:           'none',
                stroke:         'currentColor',
                strokeWidth:    1.8,
                strokeLinecap:  'round',
                strokeLinejoin: 'round',
                focusable:      'false',
            },
            createElement( 'rect', { x: 7, y: 2, width: 10, height: 20, rx: 2 } ),
            createElement( 'path', { d: 'M11 18h2' } )
        )
    );

    return createElement(
        'span',
        { className: 'mpav-method-label' },
        icon,
        createElement( 'span', null, decodeEntities( settings.title || 'Manual Bank / Mobile Payment' ) )
    );
}

// ─── Registration ────────────────────────────────────────────────────────────

registerPaymentMethod( {
    name:              'mpav',
    label:             createElement( MpavLabel, null ),
    content:           createElement( MpavPaymentMethod, null ),
    edit:              createElement( 'div', { className: 'mpav-editor-preview' },
                           decodeEntities( settings.title || 'Manual Bank / Mobile Payment' )
                       ),
    canMakePayment:    () => true,
    ariaLabel:         decodeEntities( settings.title || 'Manual Bank / Mobile Payment' ),
    supports: {
        features: [ 'products' ],
    },
} );
