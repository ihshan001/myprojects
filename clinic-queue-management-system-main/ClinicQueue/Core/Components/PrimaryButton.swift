import SwiftUI


struct PrimaryButton: View {
    
    var title: String
    var backgroundColor: Color = AppColors.primary
    var textColor: Color = .white
    var cornerRadius: CGFloat = 100
    var maxWidth: CGFloat = .infinity
    var action: () -> Void
    
    
    
    var body: some View {
        Button(action: action) {
            Text(title)
                .foregroundColor(textColor)
                .font(.app(.button))
                .padding(16)
                .frame(maxWidth: .infinity)
                .background(backgroundColor)
                .cornerRadius(cornerRadius)
                .frame(maxWidth: maxWidth )
        }
        
    }
  
}

#Preview {
    PrimaryButton(title: "Booking", maxWidth: 220) {
        print("Continue to next step")
    }
}

