import SwiftUI

enum QueueStage: String {
    case wait = "Wait"
    case next = "Next"
    case ready = "Ready"
    case inProgress = "InProgress"
    case completed = "Completed"
}

struct QueueBanner: View {
    var queueNumber: String = "11"
    var queueStage: QueueStage = .wait
    var nowServingNumber: String = "08"
    var estimatedWait: String = "15 min"
    
    var body: some View {
        ZStack(alignment: .top) {
            Image("welcome_bg")
                .resizable()
                .scaledToFill()
                .frame(width:450,  height: 470)
                .ignoresSafeArea(edges: .top)
     
            VStack(spacing: 20) {
                switch queueStage {
                case .wait:
                    queueStatusView(title: "Your Queue Number", number: queueNumber, size: CGSize(width: 100, height: 100))
                    queueStatusView(title: "Now Serving", number: nowServingNumber, size: CGSize(width: 40, height: 40), fontSize: 18)
                    queueStatusView(title: "Estimated Wait Time", number: estimatedWait, size: CGSize(width: 120, height: 40), fontSize: 16)
                    
                case .next:
                    Text("You Are Next")
                        .font(.system(size: 28, weight: .bold))
                        .foregroundColor(.white)
                    
                    queueStatusView(title: "Your Queue Number", number: queueNumber, size: CGSize(width: 100, height: 100))
                    queueStatusView(title: "Now Serving", number: nowServingNumber, size: CGSize(width: 40, height: 40), fontSize: 18)
                    
                case .ready:
                    VStack(spacing: 15) {
                        Text("It’s your turn!")
                            .font(.system(size: 28, weight: .bold))
                            .foregroundColor(.white)
                        
                        queueStatusView(title: "Your Queue Number", number: queueNumber, size: CGSize(width: 100, height: 100))
                        
                        VStack(spacing: 10) {
                            Text("Show this QR code at the counter")
                                .font(.system(size: 18, weight: .semibold))
                                .foregroundColor(.white)
                            
                            Image(systemName: "qrcode")
                                .resizable()
                                .interpolation(.none)
                                .frame(width: 120, height: 120)
                                .padding()
                                .background(Color.white)
                                .cornerRadius(12)
                        }
                    }
                    
                case .inProgress:
                    VStack(spacing: 20) {
                        Text("You Are Currently Being Served")
                            .font(.system(size: 24, weight: .bold))
                            .foregroundColor(.white)
                            .multilineTextAlignment(.center)
                        
                        queueStatusView(title: "Queue Number", number: queueNumber, size: CGSize(width: 100, height: 100))
                        
                        ProgressView()
                            .progressViewStyle(CircularProgressViewStyle(tint: AppColors.primary))
                            .scaleEffect(2)
                            .padding(.top, 20)
                    }
                    
                case .completed:
                    VStack(spacing: 15) {
                        ZStack {
                            Circle()
                                .fill(AppColors.lightBakcground)
                                .frame(width: 100, height: 100)
                            
                            Image(systemName: "checkmark")
                                .font(.system(size: 40, weight: .bold))
                                .foregroundColor(AppColors.primary)
                        }
                        
                        Text("Your consultation has been completed successfully.")
                            .font(.system(size: 18, weight: .semibold))
                            .foregroundColor(.white)
                            .multilineTextAlignment(.center)
                            .frame(width: 300)
                    }
                }
                
                Spacer()
                
            }
            .padding(.top, 0)
            .frame(maxWidth: .infinity)
        }
    }
    
    @ViewBuilder
    private func queueStatusView(title: String, number: String, size: CGSize, fontSize: CGFloat = 24) -> some View {
        VStack(spacing: 20) {
            Text(title)
                .font(.system(size: fontSize - 6, weight: .semibold))
                .foregroundColor(.white)
            
            Text(number)
                .font(.system(size: fontSize, weight: .bold))
                .foregroundColor(.white)
                .frame(width: size.width, height: size.height)
                .background(AppColors.dark.opacity(0.85))
                .cornerRadius(12)
        }
    }
}

#Preview {
    QueueBanner(queueStage: .wait)
}
