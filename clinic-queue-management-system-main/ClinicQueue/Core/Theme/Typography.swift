import SwiftUI

enum AppTextStyle {
    case heading
    case subheading
    case body
    case paragraph
    case caption
    case button

    var size: CGFloat {
        switch self {
        case .heading: return 20
        case .subheading: return 16
        case .body: return 14
        case .paragraph: return 13
        case .caption: return 11
        case .button: return 16
        
        }
    }

    var weight: AppFontWeight {
        switch self {
        case .heading: return .bold
        case .subheading: return .medium
        case .body: return .regular
        case .paragraph: return .regular
        case .caption: return .regular
        case .button: return .bold
        }
    }
}

enum AppFontWeight {
    case regular, medium, bold, heavy

    var fontName: String {
        switch self {
        case .regular: return "Gilmer Regular"
        case .medium:  return "Gilmer Medium"
        case .bold:    return "Gilmer Bold"
        case .heavy:   return "Gilmer Heavy"
        }
    }
}


enum AppTextSize {
    case xs
    case sm
    case md
    case lg
    case xl
    case xxl

    var value: CGFloat {
        switch self {
        case .xs:  return 11
        case .sm:  return 13
        case .md:  return 14
        case .lg:  return 16
        case .xl:  return 20
        case .xxl: return 24
        }
    }
}



extension Font {
    static func app(_ style: AppTextStyle) -> Font {
        .custom(style.weight.fontName, size: style.size)
    }

    static func app(size: AppTextSize, weight: AppFontWeight = .regular) -> Font {
        .custom(weight.fontName, size: size.value)
    }
}
