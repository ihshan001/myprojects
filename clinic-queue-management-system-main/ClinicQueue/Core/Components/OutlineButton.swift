import SwiftUI

struct OutlineButton: View {
    var title: String
    var backgroundColor: Color = Color.clear
    var textColor: Color = .gray
    var borderRadius: CGFloat = 100
    var borderWidth: CGFloat = 2
    var borderColor: Color = .gray
    var action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(title)
                .foregroundColor(textColor)
                .font(.app(.button))
                .padding(16)
                .frame(maxWidth: .infinity)
                .background(backgroundColor)
                .cornerRadius(borderRadius)
                .overlay(
                    RoundedRectangle(cornerRadius: borderRadius)
                        .stroke(borderColor, lineWidth: borderWidth)
                )
        }
    }
}
