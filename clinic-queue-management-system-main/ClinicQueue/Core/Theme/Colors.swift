
import SwiftUI

enum AppColors {
    static let primary = Color(hex: "169A8F")
    static let secondary = Color(hex: "AABBCC")
    static let heading = Color(hex: "169A8F")
    static let subHeading = Color(hex: "169A8F")
    static let text = Color(hex: "737373")
    static let error = Color(hex: "FF3B30")
    static let placeholder = Color(hex: "A5A5A5")
    static let border = Color(hex: "E8EBED")
    static let lableColor = Color(hex: "C1C0C0")
    static let cardBackground = Color(hex: "F2F9F7")
    static let lightBakcground = Color(hex: "ffffff")
    static let dark = Color(hex: "000000")
    static let pink = Color(hex: "FE8382")
  
    static let textdark = Color(hex: "1E1E1E")
    static let categoryHighlight = Color(hex: "169A8F").opacity(0.29)
}


extension Color {
    init(hex: String) {
        let hex = hex.trimmingCharacters(in: CharacterSet.alphanumerics.inverted)
        var int: UInt64 = 0
        Scanner(string: hex).scanHexInt64(&int)
        let a, r, g, b: UInt64
        switch hex.count {
        case 3:
            (a, r, g, b) = (255, (int >> 8) * 17, (int >> 4 & 0xF) * 17, (int & 0xF) * 17)
        case 6:
            (a, r, g, b) = (255, int >> 16, int >> 8 & 0xFF, int & 0xFF)
        case 8:
            (a, r, g, b) = (int >> 24, int >> 16 & 0xFF, int >> 8 & 0xFF, int & 0xFF)
        default:
            (a, r, g, b) = (255, 0, 0, 0)
        }
        self.init(
            .sRGB,
            red: Double(r) / 255,
            green: Double(g) / 255,
            blue: Double(b) / 255,
            opacity: Double(a) / 255
        )
    }
}
