package qa.albahar.customer.liveorder

import android.content.Context
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Paint
import android.graphics.RectF
import qa.albahar.customer.R
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt

/**
 * Draws the pictures of the notification: the four-stage segmented progress bar (check, store, scooter or bag, pin),
 * a slim version of it for the collapsed card, and the current-stage badge for the Android 16 progress style. There is
 * deliberately no logo here: the system header already shows the small brand icon and the app name, and a second brand
 * mark inside the card made the notification look as if it carried two logos.
 *
 * A RemoteViews layout cannot size a view by a fraction or tint a shape per call, so the bar is one bitmap, drawn at
 * the density of the phone (capped, because the whole notification must stay well under the 1 MB binder limit).
 */
internal object LiveOrderBarRenderer {
    private const val MAX_SCALE = 2.75f

    private const val BAR_HEIGHT_DP = 28f
    private const val ICON_DP = 24f
    private const val SEGMENT_DP = 4f
    private const val GAP_DP = 6f
    private const val COMPACT_HEIGHT_DP = 4f
    private const val COMPACT_GAP_DP = 4f

    /**
     * The ring around the current stage. It needs 2 dp (gap + stroke) around the 24 dp circle: the bar is 28 dp high for
     * that, and [EDGE_DP] keeps the first and last circle (and their ring) inside the bitmap sideways.
     */
    private const val RING_GAP_DP = 0.5f
    private const val RING_STROKE_DP = 1.5f
    private const val RING_ALPHA = 0x59
    private const val EDGE_DP = 2f

    private fun scale(context: Context): Float = min(context.resources.displayMetrics.density, MAX_SCALE)

    private fun paint(color: Int, style: Paint.Style = Paint.Style.FILL) = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        this.color = color
        this.style = style
    }

    /** The bar with its stage icons. [widthDp] is the width the card has to give it; [rtl] draws it right to left. */
    fun bar(context: Context, order: LiveOrder, colors: LiveOrderColors.Palette, widthDp: Float, rtl: Boolean): Bitmap {
        val s = scale(context)
        val w = (widthDp * s).roundToInt()
        val h = (BAR_HEIGHT_DP * s).roundToInt()
        val bitmap = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(bitmap)

        val d = ICON_DP * s
        val t = SEGMENT_DP * s
        val gap = GAP_DP * s
        val edge = EDGE_DP * s
        val seg = max((w - 2 * edge - 4 * d - 6 * gap) / 3f, t * 2)
        val cy = h / 2f
        val pitch = d + 2 * gap + seg
        // Left edge of a shape of width [width] that starts [x] from the leading edge (inside the [edge] margin).
        fun place(x: Float, width: Float) = if (rtl) w - edge - x - width else edge + x

        val trackPaint = paint(colors.track)
        val fillPaint = paint(colors.fill)
        val fills = order.segmentFill()
        for (j in 0 until LiveOrder.STAGES - 1) {
            val left = place(j * pitch + d + gap, seg)
            drawSegment(canvas, left, cy, seg, t, fills[j], rtl, trackPaint, fillPaint)
        }
        for (i in 0 until LiveOrder.STAGES) {
            val cx = place(i * pitch, d) + d / 2
            drawStage(context, canvas, order, colors, i, cx, cy, d, rtl)
        }
        return bitmap
    }

    /** Only the three segments, for the one-line collapsed card. */
    fun compactBar(context: Context, order: LiveOrder, colors: LiveOrderColors.Palette, widthDp: Float, rtl: Boolean): Bitmap {
        val s = scale(context)
        val w = (widthDp * s).roundToInt()
        val h = (COMPACT_HEIGHT_DP * s).roundToInt()
        val bitmap = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(bitmap)
        val gap = COMPACT_GAP_DP * s
        val seg = (w - 2 * gap) / 3f
        val trackPaint = paint(colors.track)
        val fillPaint = paint(colors.fill)
        val fills = order.segmentFill()
        for (j in 0 until LiveOrder.STAGES - 1) {
            val x = j * (seg + gap)
            val left = if (rtl) w - x - seg else x
            drawSegment(canvas, left, h / 2f, seg, h.toFloat(), fills[j], rtl, trackPaint, fillPaint)
        }
        return bitmap
    }

    /** One stage as a round badge on a transparent square: the tracker icon of the Android 16 progress style. */
    fun stageBadge(context: Context, order: LiveOrder, colors: LiveOrderColors.Palette, sizeDp: Float, rtl: Boolean): Bitmap {
        val px = (sizeDp * scale(context)).roundToInt().coerceAtLeast(1)
        val bitmap = Bitmap.createBitmap(px, px, Bitmap.Config.ARGB_8888)
        val half = px / 2f
        drawStage(context, Canvas(bitmap), order, colors, order.step, half, half, px.toFloat(), rtl, ring = false)
        return bitmap
    }

    private fun drawSegment(
        canvas: Canvas,
        left: Float,
        cy: Float,
        length: Float,
        thickness: Float,
        fraction: Double,
        rtl: Boolean,
        track: Paint,
        fill: Paint,
    ) {
        val r = thickness / 2
        val top = cy - r
        val bottom = cy + r
        canvas.drawRoundRect(RectF(left, top, left + length, bottom), r, r, track)
        if (fraction <= 0.0) return
        // A fill is never narrower than its own height, so a first hint of progress still reads as a pill.
        val filled = max(thickness, (length * fraction.coerceIn(0.0, 1.0)).toFloat()).coerceAtMost(length)
        val rect = if (rtl) RectF(left + length - filled, top, left + length, bottom) else RectF(left, top, left + filled, bottom)
        canvas.drawRoundRect(rect, r, r, fill)
    }

    /** Done stages are brand circles with a check, the current one a circle with its glyph, later ones a grey glyph. */
    private fun drawStage(
        context: Context,
        canvas: Canvas,
        order: LiveOrder,
        colors: LiveOrderColors.Palette,
        index: Int,
        cx: Float,
        cy: Float,
        diameter: Float,
        rtl: Boolean,
        ring: Boolean = true,
    ) {
        val done = order.isFinal && !order.isStopped || index < order.step
        val current = index == order.step && !done
        val circlePaint = paint(colors.fill)
        when {
            done -> {
                canvas.drawCircle(cx, cy, diameter / 2, circlePaint)
                glyph(context, canvas, R.drawable.ic_live_check, cx, cy, diameter * 0.62f, colors.onFill, false)
            }
            current -> {
                // A soft ring marks where the order is now (not for a stopped order: grey says enough).
                if (ring && !order.isStopped) {
                    val r = diameter / 2 + RING_GAP_DP * scale(context) + RING_STROKE_DP * scale(context) / 2
                    val ringPaint = paint(colors.fill, Paint.Style.STROKE).apply {
                        strokeWidth = RING_STROKE_DP * scale(context)
                        alpha = RING_ALPHA
                    }
                    canvas.drawCircle(cx, cy, r, ringPaint)
                }
                canvas.drawCircle(cx, cy, diameter / 2, circlePaint)
                val icon = if (order.isStopped) R.drawable.ic_live_close else stageGlyph(index, order.pickup)
                // The scooter faces the way the order travels, which is leftwards in a right-to-left layout.
                val flip = rtl && icon == R.drawable.ic_live_scooter
                glyph(context, canvas, icon, cx, cy, diameter * 0.62f, colors.onFill, flip)
            }
            else -> {
                val icon = stageGlyph(index, order.pickup)
                val flip = rtl && icon == R.drawable.ic_live_scooter
                glyph(context, canvas, icon, cx, cy, diameter * 0.9f, colors.muted, flip)
            }
        }
    }

    private fun stageGlyph(index: Int, pickup: Boolean): Int = when (index) {
        0 -> R.drawable.ic_live_check
        1 -> R.drawable.ic_live_store
        2 -> if (pickup) R.drawable.ic_live_bag else R.drawable.ic_live_scooter
        else -> if (pickup) R.drawable.ic_live_check else R.drawable.ic_live_pin
    }

    private fun glyph(context: Context, canvas: Canvas, resId: Int, cx: Float, cy: Float, size: Float, color: Int, flip: Boolean) {
        val drawable = context.getDrawable(resId)?.mutate() ?: return
        drawable.setTint(color)
        val half = size / 2
        drawable.setBounds((cx - half).roundToInt(), (cy - half).roundToInt(), (cx + half).roundToInt(), (cy + half).roundToInt())
        canvas.save()
        if (flip) canvas.scale(-1f, 1f, cx, cy)
        drawable.draw(canvas)
        canvas.restore()
    }
}
