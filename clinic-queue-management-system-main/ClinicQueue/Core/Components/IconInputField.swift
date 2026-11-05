import SwiftUI

struct IconInputField: View {
    
    var iconName: String?
    var placeholder: String
    
    @Binding var value: String 
    
    var placeholderColor: Color = AppColors.placeholder
    var backgroundColor: Color = .clear
    var textColor: Color = AppColors.text
    var borderRadius: CGFloat = 100
    var borderWidth: CGFloat = 1
    var borderColor: Color = AppColors.border
    var padding: CGFloat = 12
    var action: () -> Void = {}
    
    var body: some View {
        HStack(spacing: 8) {
            
            if let iconName {
                Image(iconName)
                    .resizable()
                    .scaledToFit()
                    .frame(width: 20, height: 20)
            }
            
            TextField(placeholder, text: $value, onEditingChanged: { _ in
                action()
            })
            .foregroundColor(textColor)
        }
        .padding(padding)
        .background(backgroundColor)
        .overlay(
            RoundedRectangle(cornerRadius: borderRadius)
                .stroke(borderColor, lineWidth: borderWidth)
        )
    }
}
