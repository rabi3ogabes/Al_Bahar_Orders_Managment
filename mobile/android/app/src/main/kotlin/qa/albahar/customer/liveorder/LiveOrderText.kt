package qa.albahar.customer.liveorder

import android.content.Context
import android.content.res.Configuration
import android.content.res.Resources
import qa.albahar.customer.R
import java.text.DateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone

/**
 * The words of the notification, in the app's language (the customer's in-app choice, not necessarily the device's).
 * The text normally arrives ready-made from the server or from the Dart side; this fills in what is missing, so a push
 * that only carries a step still reads well.
 */
internal class LiveOrderText private constructor(private val res: Resources, private val locale: Locale) {

    fun headline(o: LiveOrder, now: Long): String {
        o.headline?.let { return it }
        val at = o.updatedMs.takeIf { it > 0 } ?: now
        return when (o.phase) {
            LivePhase.DELIVERED -> res.getString(R.string.live_order_headline_delivered, time(at))
            LivePhase.COLLECTED -> res.getString(R.string.live_order_headline_collected, time(at))
            LivePhase.CANCELLED -> res.getString(R.string.live_order_headline_cancelled)
            LivePhase.FAILED -> res.getString(R.string.live_order_headline_failed)
            else -> o.etaLabel ?: etaWindow(o) ?: o.title ?: res.getString(R.string.live_order_headline_number, o.number)
        }
    }

    fun subtitle(o: LiveOrder): String = o.subtitle ?: o.body ?: res.getString(
        when (o.phase) {
            LivePhase.PLACED -> R.string.live_order_status_placed
            LivePhase.PREPARING -> R.string.live_order_status_preparing
            LivePhase.ON_THE_WAY -> R.string.live_order_status_on_the_way
            LivePhase.READY -> R.string.live_order_status_ready
            LivePhase.DELIVERED -> R.string.live_order_status_delivered
            LivePhase.COLLECTED -> R.string.live_order_status_collected
            LivePhase.CANCELLED -> R.string.live_order_status_cancelled
            LivePhase.FAILED -> R.string.live_order_status_failed
        },
    )

    /** "Driver: Ahmed · Motorbike" while the order is on the road; null otherwise. */
    fun detail(o: LiveOrder): String? {
        val name = o.driverName ?: return null
        if (o.phase != LivePhase.ON_THE_WAY) return null
        val vehicle = o.vehicle
        return if (vehicle == null) res.getString(R.string.live_order_driver, name)
        else res.getString(R.string.live_order_driver_vehicle, name, vehicle)
    }

    fun stageLabel(o: LiveOrder, index: Int): String {
        o.stageLabels.getOrNull(index)?.let { return it }
        return res.getString(
            when (index) {
                0 -> R.string.live_order_stage_placed
                1 -> R.string.live_order_stage_preparing
                2 -> if (o.pickup) R.string.live_order_stage_ready else R.string.live_order_stage_on_the_way
                else -> if (o.pickup) R.string.live_order_stage_collected else R.string.live_order_stage_delivered
            },
        )
    }

    /** What a screen reader says for the progress bar, which is a picture. */
    fun progressDescription(o: LiveOrder): String {
        o.progressDescription?.let { return it }
        val stage = stageLabel(o, o.step)
        return if (o.isStopped) res.getString(R.string.live_order_progress_description_stopped, stage)
        else res.getString(R.string.live_order_progress_description, stage, o.step + 1, LiveOrder.STAGES)
    }

    fun actionLabel(o: LiveOrder): String =
        res.getString(if (o.isFinal) R.string.live_order_action_view else R.string.live_order_action_track)

    fun callDriverLabel(): String = res.getString(R.string.live_order_action_call_driver)

    fun groupSummary(count: Int): String = res.getQuantityString(R.plurals.live_order_group_summary, count, count)

    fun appName(): String = res.getString(R.string.app_name)

    fun channelName(): String = res.getString(R.string.live_order_channel_name)

    fun channelDescription(): String = res.getString(R.string.live_order_channel_description)

    private fun etaWindow(o: LiveOrder): String? {
        val from = o.etaFromMs
        val to = o.etaToMs
        if (from <= 0 && to <= 0) return null
        if (from > 0 && to > 0 && time(from) != time(to)) {
            return res.getString(R.string.live_order_arrives_between, time(minOf(from, to)), time(maxOf(from, to)))
        }
        return res.getString(R.string.live_order_arrives_at, time(if (to > 0) to else from))
    }

    /** Doha wall-clock time, like every other time in the app (Qatar has no daylight saving). */
    fun time(ms: Long): String {
        val format = DateFormat.getTimeInstance(DateFormat.SHORT, locale)
        format.timeZone = TimeZone.getTimeZone("Asia/Qatar")
        return format.format(Date(ms))
    }

    val isRtl: Boolean get() = locale.language in RTL_LANGUAGES

    companion object {
        private val RTL_LANGUAGES = setOf("ar", "he", "fa", "ur")
        private const val FLUTTER_PREFS = "FlutterSharedPreferences"

        /** Payload locale first, then the language chosen in the app (Flutter's saved preference), then the device's. */
        fun forOrder(context: Context, o: LiveOrder?): LiveOrderText {
            val code = o?.locale ?: appLanguage(context)
            // Times use Western digits, like the rest of the app, unless the payload asks for Arabic-Indic ones.
            val tag = if (code == "ar") "ar-u-nu-${if (o?.arabicDigits == true) "arab" else "latn"}" else code
            val locale = Locale.forLanguageTag(tag)
            val config = Configuration(context.resources.configuration)
            config.setLocale(locale)
            config.setLayoutDirection(locale)
            return LiveOrderText(context.createConfigurationContext(config).resources, locale)
        }

        fun appLanguage(context: Context): String {
            val saved = flutterPref(context, "locale")
            if (saved == "ar" || saved == "en") return saved
            // Same rule as the Dart side: English devices get English, everything else Arabic.
            return if (Locale.getDefault().language == "en") "en" else "ar"
        }

        /** A string saved by the shared_preferences plugin (keys are stored with a `flutter.` prefix). */
        fun flutterPref(context: Context, key: String): String? = try {
            context.getSharedPreferences(FLUTTER_PREFS, Context.MODE_PRIVATE).getString("flutter.$key", null)
        } catch (_: Exception) {
            null
        }
    }
}
