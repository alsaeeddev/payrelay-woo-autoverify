package com.alsaeeddev.payrelay

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.google.android.gms.tasks.Tasks
import com.google.mlkit.vision.barcode.BarcodeScannerOptions
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.common.InputImage
import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.qrcode.QRCodeWriter
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Instrumented test (runs on a device/emulator): src/androidTest/...
 *
 * ML Kit needs native code, so this can't run as a Robolectric/JVM unit test.
 * It uses the same configuration as QrCodeAnalyzer (QR_CODE format only)
 * and checks that pairing JSON payloads are decoded correctly.
 */
@RunWith(AndroidJUnit4::class)
class QrScanDecodeTest {

    private val scanner = BarcodeScanning.getClient(
        BarcodeScannerOptions.Builder()
            .setBarcodeFormats(Barcode.FORMAT_QR_CODE)
            .build()
    )

    @After
    fun tearDown() {
        scanner.close()
    }

    @Test
    fun decodesDensePairingJsonQr() {
        val payload =
            """{"site":"https://merchant-store.example.com","token":"pair_tok_9876543210abcdef"}"""

        val result = decode(renderQr(payload, size = 480))

        assertEquals(listOf(payload), result)
    }

    @Test
    fun decodesAnotherPairingPayload() {
        val payload = """{"site":"https://shop.example.pk","token":"tok_dark_mode_123456"}"""

        val result = decode(renderQr(payload, size = 480))

        assertEquals(listOf(payload), result)
    }

    @Test
    fun decodesSmallCenteredQrInsideNoisyFrame() {
        val payload = """{"site":"https://woo.example.pk","token":"tok_center_crop_7890"}"""
        val frameW = 1280
        val frameH = 720
        val qr = renderQr(payload, size = 320)

        // Dark/bright stripes: a noisy background like a laptop desk and browser bars
        val frame = Bitmap.createBitmap(frameW, frameH, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(frame)
        val paint = Paint()
        for (y in 0 until frameH step 8) {
            paint.color = if ((y / 8) % 2 == 0) Color.rgb(35, 35, 35) else Color.rgb(210, 210, 210)
            canvas.drawRect(0f, y.toFloat(), frameW.toFloat(), (y + 8).toFloat(), paint)
        }
        canvas.drawBitmap(qr, ((frameW - 320) / 2).toFloat(), ((frameH - 320) / 2).toFloat(), null)

        val result = decode(frame)

        assertEquals(listOf(payload), result)
    }

    @Test
    fun blankFrameReturnsNoQr() {
        val blank = Bitmap.createBitmap(480, 480, Bitmap.Config.ARGB_8888).apply {
            eraseColor(Color.WHITE)
        }

        val result = decode(blank)

        assertTrue("Blank frame par koi QR nahi milna chahiye", result.isEmpty())
    }

    // ---- helpers ----

    /** Same as the analyzer: trim rawValue, then drop empty values. */
    private fun decode(bitmap: Bitmap): List<String> {
        val barcodes = Tasks.await(scanner.process(InputImage.fromBitmap(bitmap, 0)))
        return barcodes.mapNotNull { it.rawValue?.trim() }.filter { it.isNotEmpty() }
    }

    private fun renderQr(content: String, size: Int): Bitmap {
        val matrix = QRCodeWriter().encode(
            content,
            BarcodeFormat.QR_CODE,
            size,
            size,
            mapOf(EncodeHintType.MARGIN to 4)
        )
        val bmp = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888)
        for (y in 0 until size) {
            for (x in 0 until size) {
                bmp.setPixel(x, y, if (matrix[x, y]) Color.BLACK else Color.WHITE)
            }
        }
        return bmp
    }
}