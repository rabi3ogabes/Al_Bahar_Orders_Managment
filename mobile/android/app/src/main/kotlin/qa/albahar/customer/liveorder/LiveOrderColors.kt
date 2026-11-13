package qa.albahar.customer.liveorder

/** WCAG contrast maths and the colours of the progress bar, on opaque ARGB ints (no Android classes, so it is plain JVM). */
object LiveOrderColors {
    /** Colours of one drawn bar. [fill] is the brand colour (grey for a stopped order), [onFill] reads on it (AA). */
    data class Palette(val fill: Int, val onFill: Int, val track: Int, val muted: Int)

    private const val WHITE = 0xFFFFFFFF.toInt()
    private const val NEAR_BLACK = 0xFF14090D.toInt()

    // The shade is light or dark with the system theme. These are the worst-case surfaces the bar must stay visible on.
    private const val LIGHT_SURFACE = 0xFFFFFFFF.toInt()
    private const val DARK_SURFACE = 0xFF3A3A3A.toInt()

    private const val MIN_GRAPHIC_CONTRAST = 3.0

    fun palette(brand: Int, stopped: Boolean, night: Boolean): Palette {
        val surface = if (night) DARK_SURFACE else LIGHT_SURFACE
        val grey = if (night) 0xFFA8A8A8.toInt() else 0xFF757575.toInt()
        val fill = if (stopped) grey else ensureContrast(brand or (0xFF shl 24), surface, MIN_GRAPHIC_CONTRAST)
        return Palette(
            fill = fill,
            onFill = readableOn(fill),
            track = if (night) 0xFF5A5A5A.toInt() else 0xFFD3D3D3.toInt(),
            muted = grey,
        )
    }

    fun parseHex(value: String?): Int? {
        val s = value?.trim()?.removePrefix("#") ?: return null
        if (s.length != 6) return null
        val rgb = s.toIntOrNull(16) ?: return null
        return rgb or (0xFF shl 24)
    }

    fun luminance(color: Int): Double {
        fun channel(v: Int): Double {
            val c = v / 255.0
            return if (c <= 0.03928) c / 12.92 else Math.pow((c + 0.055) / 1.055, 2.4)
        }
        return 0.2126 * channel((color shr 16) and 0xFF) + 0.7152 * channel((color shr 8) and 0xFF) +
            0.0722 * channel(color and 0xFF)
    }

    fun contrast(a: Int, b: Int): Double {
        val la = luminance(a)
        val lb = luminance(b)
        return (maxOf(la, lb) + 0.05) / (minOf(la, lb) + 0.05)
    }

    /** White or near-black, whichever reads better on [background]. */
    fun readableOn(background: Int): Int =
        if (contrast(background, WHITE) >= contrast(background, NEAR_BLACK)) WHITE else NEAR_BLACK

    /** Moves [fg] towards white (on a dark [bg]) or black (on a light one) until it reaches [min] contrast. */
    fun ensureContrast(fg: Int, bg: Int, min: Double): Int {
        if (contrast(fg, bg) >= min) return fg
        val towards = if (luminance(bg) < 0.5) WHITE else 0xFF000000.toInt()
        for (step in 1..10) {
            val c = mix(fg, towards, step / 10.0)
            if (contrast(c, bg) >= min) return c
        }
        return towards
    }

    private fun mix(from: Int, to: Int, t: Double): Int {
        fun lerp(shift: Int): Int {
            val a = (from shr shift) and 0xFF
            val b = (to shr shift) and 0xFF
            return (a + (b - a) * t).toInt().coerceIn(0, 255)
        }
        return (0xFF shl 24) or (lerp(16) shl 16) or (lerp(8) shl 8) or lerp(0)
    }
}
