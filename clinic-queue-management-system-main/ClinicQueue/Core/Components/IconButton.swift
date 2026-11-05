import SwiftUI

struct IconButton: View {
    var icon: String = ""
    var label: String? = nil
    var width: CGFloat = 20
    var height: CGFloat = 20
    var padding: CGFloat = 12
    var backgroundColor: Color = .clear
    var cornerRadius: CGFloat = 10
    var buttonType: String? = nil
    var action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 8) {
                Image(buttonType == "back" ? "LeftArrow" : icon)
                    .resizable()
                    .scaledToFit()
                    .frame(width: width, height: height)
                
                if let label = label {
                    Text(label)
                        .font(.app(.button)) 
                        .foregroundColor(.primary)
                }
            }
            .padding(padding)
            .background(backgroundColor)
            .cornerRadius(cornerRadius)
        }
    }
}
