import SwiftUI

struct QueueButton: View {
    var title: String
    var subText: String
    var backgroundColor: Color = .clear
    var borderColor: Color = AppColors.border
    var activeBackgroundColor: Color = AppColors.primary
    var activeBorderColor: Color = AppColors.secondary
    var width: CGFloat = 100
    var height: CGFloat = 80
    var action: () -> Void

    @Binding var isActive: Bool

    var body: some View {
        Button(action: {
            isActive.toggle()
            action()
        }) {
            VStack {
                Text(title)
                    .font(.app(.heading))
                    .multilineTextAlignment(.center)
                    .padding(.bottom, 8) 
                
                if !subText.isEmpty {
                    Text(subText)
                        .font(.app(.caption))
                        .multilineTextAlignment(.center)
                        .padding(.top, 8)
                }
            }
            .frame(width: width, height: height, alignment: .center)
            .background(isActive ? activeBackgroundColor : backgroundColor)
            .border(isActive ? activeBorderColor : borderColor, width: 2)
            .cornerRadius(10)
            .padding(8)
        }
    }
}
