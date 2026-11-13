package qa.albahar.customer.liveorder

import android.content.Context
import org.json.JSONObject

/**
 * What the notification remembers per order, in private preferences: the last drawn state (to drop duplicates and
 * stale updates, also across the app being killed), whether the customer swiped it away, and when it should expire.
 */
internal class LiveOrderStore(context: Context) {
    private val prefs = context.getSharedPreferences(NAME, Context.MODE_PRIVATE)

    data class Entry(val snapshot: LiveSnapshot, val dismissed: Boolean, val touchedMs: Long, val expiresMs: Long)

    fun get(orderId: String): Entry? {
        val raw = prefs.getString(KEY + orderId, null) ?: return null
        return try {
            val json = JSONObject(raw)
            val phase = LivePhase.parse(json.getString("phase")) ?: return null
            Entry(
                snapshot = LiveSnapshot(json.getLong("seq"), json.getLong("updated"), phase, json.getInt("step"), json.getString("sig")),
                dismissed = json.optBoolean("dismissed", false),
                touchedMs = json.optLong("touched", 0L),
                expiresMs = json.optLong("expires", 0L),
            )
        } catch (_: Exception) {
            null
        }
    }

    fun put(orderId: String, entry: Entry) {
        prefs.edit().putString(KEY + orderId, encode(entry)).apply()
    }

    private fun encode(entry: Entry): String {
        val s = entry.snapshot
        return JSONObject()
            .put("seq", s.seq)
            .put("updated", s.updatedMs)
            .put("phase", s.phase.wire)
            .put("step", s.step)
            .put("sig", s.signature)
            .put("dismissed", entry.dismissed)
            .put("touched", entry.touchedMs)
            .put("expires", entry.expiresMs)
            .toString()
    }

    fun markDismissed(orderId: String, now: Long) {
        val entry = get(orderId) ?: return
        put(orderId, entry.copy(dismissed = true, touchedMs = now, expiresMs = 0L))
    }

    fun remove(orderId: String) {
        prefs.edit().remove(KEY + orderId).apply()
    }

    /** Forgets every order (sign-out); the "permission asked" flag belongs to the device and stays. */
    fun clear() {
        val edit = prefs.edit()
        prefs.all.keys.filter { it.startsWith(KEY) }.forEach { edit.remove(it) }
        edit.apply()
    }

    /**
     * Forgets orders untouched for two days (a stale update arriving later can no longer be mistaken for a new one) and
     * returns the ids whose notification is past its expiry, for Android versions that cannot time a notification out.
     */
    fun purge(now: Long): List<String> {
        val expired = mutableListOf<String>()
        val edit = prefs.edit()
        for (key in prefs.all.keys.filter { it.startsWith(KEY) }) {
            val id = key.removePrefix(KEY)
            val entry = get(id)
            if (entry == null || now - entry.touchedMs > KEEP_MS) {
                edit.remove(key)
            } else if (entry.expiresMs in 1..now) {
                expired += id
                edit.putString(key, encode(entry.copy(expiresMs = 0L)))
            }
        }
        edit.apply()
        return expired
    }

    /** True once the system permission dialog has been shown (tells "not asked yet" from "denied for good"). */
    var permissionAsked: Boolean
        get() = prefs.getBoolean(PERMISSION_ASKED, false)
        set(value) {
            prefs.edit().putBoolean(PERMISSION_ASKED, value).apply()
        }

    private companion object {
        const val NAME = "albahar_live_order"
        const val KEY = "order."
        const val PERMISSION_ASKED = "permission_asked"
        const val KEEP_MS = 48L * 60 * 60 * 1000
    }
}
