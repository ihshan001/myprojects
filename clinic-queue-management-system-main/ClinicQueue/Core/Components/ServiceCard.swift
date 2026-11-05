import SwiftUI

struct ServiceCard: View {
    let service: Service
    var isHighlighted: Bool = false

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
           
            ZStack {
                RoundedRectangle(cornerRadius: 12, style: .continuous)
                    .fill(Color.white.opacity(0.8))
                    .frame(width: 44, height: 44)
                    .overlay(
                        RoundedRectangle(cornerRadius: 12, style: .continuous)
                            .stroke(isHighlighted ? AppColors.primary.opacity(0.6) : Color.black.opacity(0.06),
                                    lineWidth: 1)
                    )

                Image(systemName: service.icon)
                    .font(.system(size: 20))
                    .foregroundColor(AppColors.textdark)
            }
            
           
            VStack(alignment: .leading, spacing: 4) {
                Text(service.title)
                    .font(.system(size: 16, weight: .bold))
                    .foregroundColor(AppColors.textdark)
                    .lineLimit(2)
                    .multilineTextAlignment(.leading)
                
                Text(service.subtitle)
                    .font(.system(size: 12))
                    .foregroundColor(AppColors.text)
                    .lineLimit(3)
                    .multilineTextAlignment(.leading)
                    .fixedSize(horizontal: false, vertical: true)
            }
            
            Spacer(minLength: 0)
        }
       
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(service.background)
        .cornerRadius(24)
        .shadow(color: Color.black.opacity(0.05), radius: 10, x: 0, y: 4)
        .overlay(
            RoundedRectangle(cornerRadius: 24, style: .continuous)
                .stroke(isHighlighted ? AppColors.primary : Color.clear, lineWidth: 2)
        )
    }
}
