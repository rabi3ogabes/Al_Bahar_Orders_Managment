package qa.albahar.customer.liveorder

import java.time.Instant
import java.time.OffsetDateTime
import kotlin.math.roundToInt

/**
 * Where an order is on its way. [step] is the stage the progress bar stops at (0..3): placed, preparing, on the way
 * (ready, for pickup orders) and delivered (collected). A stopped order (cancelled, failed) keeps the stage it reached.
 */
enum class LivePhase(val wire: String, val step: Int, val isFinal: Boolean, val isStopped: Boolean) {
    PLACED("placed", 0, false, false),
    PREPARING("preparing", 1, false, false),
    ON_THE_WAY("on_the_way", 2, false, false),
    READY("ready", 2, false, false),
    DELIVERED("delivered", 3, true, false),
    COLLECTED("collected", 3, true, false),
    CANCELLED("cancelled", 0, true, true),
    FAILED("failed", 0, true, true);

    companion object {
        fun parse(value: String?): LivePhase? {
            val v = value?.trim()?.lowercase() ?: return null
            return entries.firstOrNull { it.wire == v }
        }
    }
}

/** What the notification remembers about the last state it drew, to drop duplicates and out-of-order updates. */
data class LiveSnapshot(val seq: Long, val updatedMs: Long, val phase: LivePhase, val step: Int, val signature: String)

/**
 * One order's live state, parsed from a Dart method-channel call or from an FCM data message (same keys, see
 * docs/qa/LIVE_ORDER_NOTIFICATION.md). Parsing never throws: a payload without an order id is dropped, every other
 * missing or malformed field falls back to a safe default.
 */
data class LiveOrder(
    val orderId: String,
    val number: String,
    val pickup: Boolean,
    val phase: LivePhase,
    val step: Int,
    val sub: Double,
    val title: String?,
    val body: String?,
    val etaLabel: String?,
    val headline: String?,
    val subtitle: String?,
    val etaFromMs: Long,
    val etaToMs: Long,
    /** The driver's expected arrival (one moment, not a window), only while on the road; the card counts down to it by itself. */
    val etaAtMs: Long,
    val driverName: String?,
    val driverPhone: String?,
    val vehicle: String?,
    val seq: Long,
    val updatedMs: Long,
    val deepLink: String?,
    val brandPrimary: Int?,
    val locale: String?,
    val arabicDigits: Boolean,
    val stageLabels: List<String>,
    val progressDescription: String?,
    val force: Boolean,
) {
    val isFinal: Boolean get() = phase.isFinal
    val isStopped: Boolean get() = phase.isStopped

    /** Fill of the three segments between the four stages, 0.0 to 1.0. */
    fun segmentFill(): DoubleArray {
        val fill = DoubleArray(STAGES - 1)
        when {
            phase.isStopped || !phase.isFinal -> {
                for (j in fill.indices) {
                    fill[j] = when {
                        j < step -> 1.0
                        j == step && !phase.isStopped -> sub
                        else -> 0.0
                    }
                }
            }
            else -> fill.fill(1.0)
        }
        return fill
    }

    val signature: String
        get() = listOf(
            phase.wire, step, (sub * 20).roundToInt(), title, body, etaLabel, etaFromMs, etaToMs, etaAtMs / 60_000, driverName, driverPhone,
            vehicle, locale, arabicDigits, brandPrimary,
        ).joinToString("|")

    fun snapshot() = LiveSnapshot(seq, updatedMs, phase, step, signature)

    /**
     * True when this update should replace [prev]. Versioned updates (`seq`, then `updated_at`) are ordered by their
     * version; unversioned ones never move an order backwards and never reopen a finished one. A finished order stays
     * as it first finished: a re-read would only restamp its time.
     */
    fun supersedes(prev: LiveSnapshot?): Boolean {
        if (prev == null || force) return true
        if (prev.phase.isFinal && phase == prev.phase) return false
        if (seq > 0 && prev.seq > 0) return seq > prev.seq
        if (updatedMs > 0 && prev.updatedMs > 0 && updatedMs != prev.updatedMs) return updatedMs > prev.updatedMs
        if (signature == prev.signature) return false
        if (prev.phase.isFinal) return false
        if (phase.isFinal) return true
        return step >= prev.step
    }

    companion object {
        const val STAGES = 4
        private const val DEFAULT_SUB = 0.15
        private const val DEFAULT_SUB_READY = 0.5
        private const val MAX_TEXT = 200

        fun fromMap(m: Map<String, Any?>): LiveOrder? {
            val orderId = m.text("order_id", 80) ?: return null
            val kind = m.text("kind", 40)
            if (kind != null && kind != "order_progress") return null

            val type = (m.text("type", 20) ?: m.text("fulfillment_type", 20))?.lowercase()
            val pickup = type == "pickup"
            val total = m.long("total")?.toInt()?.takeIf { it >= 2 } ?: STAGES
            val rawStep = m.long("step")?.toInt()?.let { normaliseStep(it, total) }
            val phase = LivePhase.parse(m.text("phase", 20)) ?: derivePhase(rawStep ?: 0, m.bool("done"), pickup)
            val step = if (phase.isStopped) (rawStep ?: 0).coerceIn(0, STAGES - 1) else phase.step
            val sub = when {
                phase.isFinal -> 1.0
                else -> m.double("sub")?.takeIf { it.isFinite() }?.coerceIn(0.0, 1.0)
                    ?: if (phase == LivePhase.READY) DEFAULT_SUB_READY else DEFAULT_SUB
            }
            return LiveOrder(
                orderId = orderId,
                number = m.text("number", 40) ?: orderId,
                pickup = pickup,
                phase = phase,
                step = step,
                sub = sub,
                title = m.text("title", MAX_TEXT),
                body = m.text("body", MAX_TEXT),
                etaLabel = m.text("eta_label", MAX_TEXT),
                headline = m.text("headline", MAX_TEXT),
                subtitle = m.text("subtitle", MAX_TEXT),
                etaFromMs = m.millis("eta_from_ms", "eta_from"),
                etaToMs = m.millis("eta_to_ms", "eta_to"),
                etaAtMs = m.millis("eta_at_ms", "eta_at"),
                driverName = m.text("driver_name", 60),
                driverPhone = m.text("driver_phone", 30),
                vehicle = m.text("vehicle", 40),
                seq = (m.long("seq") ?: 0L).coerceAtLeast(0L),
                updatedMs = m.millis("updated_at_ms", "updated_at"),
                deepLink = m.text("deep_link", 300),
                brandPrimary = m.color("brand_primary") ?: brandFromJson(m["brand"]),
                locale = m.text("locale", 10)?.lowercase(),
                arabicDigits = m.bool("arabic_digits"),
                stageLabels = (m["stage_labels"] as? List<*>)?.mapNotNull { it?.toString()?.take(40) } ?: emptyList(),
                progressDescription = m.text("progress_description", MAX_TEXT),
                force = m.bool("force"),
            )
        }

        /** A server that counts stages differently (`total` other than 4) is scaled onto the four-stage bar. */
        private fun normaliseStep(step: Int, total: Int): Int {
            val clamped = step.coerceIn(0, total - 1)
            return if (total == STAGES) clamped else (clamped * (STAGES - 1).toDouble() / (total - 1)).roundToInt()
        }

        private fun derivePhase(step: Int, done: Boolean, pickup: Boolean): LivePhase = when {
            step >= 3 || done -> if (pickup) LivePhase.COLLECTED else LivePhase.DELIVERED
            step == 2 -> if (pickup) LivePhase.READY else LivePhase.ON_THE_WAY
            step == 1 -> LivePhase.PREPARING
            else -> LivePhase.PLACED
        }

        private val brandPrimaryPattern = Regex("\"primary\"\\s*:\\s*\"(#?[0-9a-fA-F]{6})\"")

        /** `brand` arrives as a map from Dart and as a JSON string in a push (FCM data values are strings). */
        private fun brandFromJson(value: Any?): Int? = when (value) {
            is Map<*, *> -> LiveOrderColors.parseHex(value["primary"]?.toString())
            is String -> brandPrimaryPattern.find(value)?.groupValues?.get(1)?.let { LiveOrderColors.parseHex(it) }
            else -> null
        }

        private fun Map<String, Any?>.text(key: String, max: Int): String? {
            val v = this[key] ?: return null
            if (v is Collection<*> || v is Map<*, *>) return null
            val s = v.toString().trim()
            return if (s.isEmpty() || s == "null") null else s.take(max)
        }

        private fun Map<String, Any?>.long(key: String): Long? = when (val v = this[key]) {
            is Number -> v.toLong()
            is String -> v.trim().toDoubleOrNull()?.takeIf { it.isFinite() }?.toLong()
            else -> null
        }

        private fun Map<String, Any?>.double(key: String): Double? = when (val v = this[key]) {
            is Number -> v.toDouble()
            is String -> v.trim().toDoubleOrNull()
            else -> null
        }

        private fun Map<String, Any?>.bool(key: String): Boolean = when (val v = this[key]) {
            is Boolean -> v
            is Number -> v.toInt() != 0
            is String -> v == "1" || v.equals("true", ignoreCase = true)
            else -> false
        }

        private fun Map<String, Any?>.color(key: String): Int? = when (val v = this[key]) {
            is Number -> v.toLong().toInt() or (0xFF shl 24)
            is String -> LiveOrderColors.parseHex(v)
            else -> null
        }

        /** Epoch milliseconds from a numeric key, or an ISO-8601 string; 0 when absent or unreadable. */
        private fun Map<String, Any?>.millis(msKey: String, isoKey: String): Long {
            long(msKey)?.takeIf { it > 0 }?.let { return it }
            val iso = text(isoKey, 40) ?: return 0L
            return try {
                OffsetDateTime.parse(iso).toInstant().toEpochMilli()
            } catch (_: Exception) {
                try {
                    Instant.parse(iso).toEpochMilli()
                } catch (_: Exception) {
                    0L
                }
            }
        }
    }
}
