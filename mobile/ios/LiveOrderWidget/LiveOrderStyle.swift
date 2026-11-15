import SwiftUI

/// Colours, words and shapes of the order Live Activity. Everything the customer reads comes from the server (title, body, stage name)
/// or from the small table below, never from the system's language files: the customer can choose Arabic or English in the app
/// whatever language the iPhone is set to, and an extension cannot read the app's choice.

// MARK: - Colour maths (WCAG contrast, the same rule as the Android card)

struct RGB: Equatable {
    var r: Double, g: Double, b: Double

    static let white = RGB(r: 1, g: 1, b: 1)
    static let black = RGB(r: 0, g: 0, b: 0)
    static let maroon = RGB(r: 0x8A / 255, g: 0x15 / 255, b: 0x38 / 255)

    /// "#8A1538" or "8A1538"; nil when it is not six hex digits.
    init?(hex: String) {
        var s = hex.trimmingCharacters(in: .whitespacesAndNewlines)
        if s.hasPrefix("#") { s.removeFirst() }
        guard s.count == 6, let v = UInt32(s, radix: 16) else { return nil }
        self.init(r: Double((v >> 16) & 0xFF) / 255, g: Double((v >> 8) & 0xFF) / 255, b: Double(v & 0xFF) / 255)
    }

    init(r: Double, g: Double, b: Double) {
        self.r = r
        self.g = g
        self.b = b
    }

    var color: Color { Color(.sRGB, red: r, green: g, blue: b, opacity: 1) }

    var luminance: Double {
        func channel(_ c: Double) -> Double { c <= 0.03928 ? c / 12.92 : pow((c + 0.055) / 1.055, 2.4) }
        return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
    }

    func contrast(with other: RGB) -> Double {
        let a = luminance, b = other.luminance
        return (max(a, b) + 0.05) / (min(a, b) + 0.05)
    }

    func mixed(with other: RGB, _ t: Double) -> RGB {
        RGB(r: r + (other.r - r) * t, g: g + (other.g - g) * t, b: b + (other.b - b) * t)
    }

    /// Moves the colour towards white (on a dark surface) or black (on a light one) until it reaches [minimum] contrast.
    func ensuringContrast(on surface: RGB, minimum: Double) -> RGB {
        if contrast(with: surface) >= minimum { return self }
        let towards: RGB = surface.luminance < 0.5 ? .white : .black
        for step in 1...10 {
            let candidate = mixed(with: towards, Double(step) / 10)
            if candidate.contrast(with: surface) >= minimum { return candidate }
        }
        return towards
    }

    /// White or near-black, whichever reads better on this colour.
    var readableOn: RGB {
        contrast(with: .white) >= contrast(with: RGB(r: 0.08, g: 0.035, b: 0.05)) ? .white : RGB(r: 0.08, g: 0.035, b: 0.05)
    }
}

// MARK: - Palette

/// The colours of one drawing. `dark` is the surface the activity sits on: the lock screen follows the system, the Dynamic Island is always dark.
struct LiveOrderPalette {
    let fill: Color
    let onFill: Color
    let track: Color
    let muted: Color
    let primaryText: Color
    let secondaryText: Color
    let chip: Color

    init(brandPrimary: String, dark: Bool, stopped: Bool = false) {
        // Worst case surfaces: a light lock screen is white, a dark one (and the island) is near black.
        let surface: RGB = dark ? RGB(r: 0.11, g: 0.11, b: 0.12) : .white
        let grey = dark ? RGB(r: 0.66, g: 0.66, b: 0.66) : RGB(r: 0.46, g: 0.46, b: 0.46)
        let brand = (RGB(hex: brandPrimary) ?? .maroon).ensuringContrast(on: surface, minimum: 3.0)
        let base = stopped ? grey : brand
        fill = base.color
        onFill = base.readableOn.color
        track = (dark ? RGB.white : RGB.black).mixed(with: surface, 0.82).color
        muted = grey.color
        primaryText = (dark ? RGB.white : RGB(r: 0.08, g: 0.035, b: 0.05)).color
        secondaryText = grey.color
        chip = (dark ? RGB.white : RGB.black).mixed(with: surface, 0.9).color
    }
}

// MARK: - Words

/// The few fixed words (everything else is the server's text, already in the customer's language).
enum LiveOrderStrings {
    static func isArabic(_ lang: String) -> Bool { lang.lowercased().hasPrefix("ar") }

    static func orderNumber(_ number: String, lang: String) -> String {
        isArabic(lang) ? "الطلب \(number)" : "Order #\(number)"
    }

    static func driver(_ name: String, lang: String) -> String {
        isArabic(lang) ? "السائق: \(name)" : "Driver: \(name)"
    }

    static func arrivesIn(lang: String) -> String { isArabic(lang) ? "يصل خلال" : "Arrives in" }

    /// The four stages of the bar (delivery) or of a pickup.
    static func stage(_ index: Int, pickup: Bool, lang: String) -> String {
        let ar = isArabic(lang)
        switch index {
        case 0: return ar ? "تم الطلب" : "Placed"
        case 1: return ar ? "قيد التحضير" : "Preparing"
        case 2: return pickup ? (ar ? "جاهز" : "Ready") : (ar ? "في الطريق" : "On the way")
        default: return pickup ? (ar ? "تم الاستلام" : "Collected") : (ar ? "تم التوصيل" : "Delivered")
        }
    }

    /// What a screen reader says for the bar, which is a picture.
    static func progressDescription(step: Int, total: Int, pickup: Bool, stopped: Bool, lang: String) -> String {
        let name = stage(step, pickup: pickup, lang: lang)
        if stopped { return isArabic(lang) ? "توقف الطلب: \(name)" : "Order stopped: \(name)" }
        return isArabic(lang) ? "تقدم الطلب: \(name)، الخطوة \(step + 1) من \(total)" : "Order progress: \(name), step \(step + 1) of \(total)"
    }
}

// MARK: - Direction and numbers

extension View {
    /// Reading direction and digits follow the app's language (not the iPhone's): Arabic reads right to left, with Western digits like the rest of the app.
    func liveOrderLanguage(_ lang: String) -> some View {
        self
            .environment(\.layoutDirection, LiveOrderStrings.isArabic(lang) ? .rightToLeft : .leftToRight)
            .environment(\.locale, Locale(identifier: LiveOrderStrings.isArabic(lang) ? "ar@numbers=latn" : "en_US"))
    }
}
