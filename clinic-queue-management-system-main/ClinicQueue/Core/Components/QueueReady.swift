
import SwiftUI
import CoreImage.CIFilterBuiltins



struct QueueReady: View {
    
    var queueNumber: String = "11"
    var nowServing: String = "05"
    var estimatedWait: String = "~15 minutes"
    var qrContent: String = "CLINIC-QUEUE-11"
    
    var body: some View {
        ZStack {
            
            Image("welcome_bg")
                .resizable()
                .scaledToFill()
                .frame(height: 600)
                .frame(width: 450)
            
            VStack(spacing: 32) {
                
                Spacer()
                
            
                VStack(spacing: 16) {
                    Text("It's your turn")
                        .font(.system(size: 22, weight: .semibold))
                        .foregroundColor(.white)
                    
                    Text(queueNumber)
                        .font(.system(size: 48, weight: .bold))
                        .foregroundColor(.white)
                        .frame(width: 120, height: 120)
                        .background(Color.black)
                        .cornerRadius(20)
                }
                VStack(spacing: 12) {
                               Text("Now Serving")
                                   .font(.system(size: 18, weight: .semibold))
                                   .foregroundColor(.white)
           
                               Text(nowServing)
                                   .font(.system(size: 28, weight: .bold))
                                   .foregroundColor(.white)
                                   .frame(width: 80, height: 60)
                                   .background(Color.black.opacity(0.8))
                                   .cornerRadius(12)
                           }
                
                VStack(spacing: 8) {
                    Text("Estimated Wait Time")
                        .font(.system(size: 16, weight: .medium))
                        .foregroundColor(.white)
                    
                    Text(estimatedWait)
                        .font(.system(size: 18, weight: .semibold))
                        .foregroundColor(.white)
                        .padding(.horizontal, 24)
                        .padding(.vertical, 12)
                        .background(Color.black.opacity(0.8))
                        .cornerRadius(20)
                }

              
              
                
                Spacer()
                Spacer()
                Spacer()
            }
            .padding(.horizontal, 24)
        }
    }
}

#Preview {
    QueueReady()
}
