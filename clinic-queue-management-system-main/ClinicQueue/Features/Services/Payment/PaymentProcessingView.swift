//
//  PaymentProcessingView.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-08.
//

import SwiftUI

struct PaymentProcessingView<SuccessDestination: View>: View {
    
    let destination: SuccessDestination
    
    @State private var navigateToStatus = false
    
    private let paymentSuccess = true
    
    var body: some View {
        
        ZStack {
            
            ScrollView {
                VStack(spacing: 20) {
                    
                    Text("Payment Processing")
                        .font(.system(size: 20, weight: .bold))
                        .frame(maxWidth: .infinity, alignment: .center)
                        .padding(.horizontal)
                    
                    ProgressView()
                        .scaleEffect(1.5)
                        .padding(.top, 20)
                    
                    Text("Please wait while we confirm your payment.")
                        .foregroundColor(AppColors.lableColor)
                        .frame(maxWidth: .infinity, alignment: .center)
                    
                    Text("This usually takes a few seconds.")
                        .foregroundColor(AppColors.lableColor)
                        .frame(maxWidth: .infinity, alignment: .center)
                }
                .padding(.vertical, 20)
            }
        }
        
        .onAppear {
            
            DispatchQueue.main.asyncAfter(deadline: .now() + 4) {
                navigateToStatus = true
            }
        }
        
        .navigationDestination(isPresented: $navigateToStatus) {
            destination
        }
    }
}

//#Preview {
//    PaymentStatusView(
//        isSuccess: true,
//        onContinue: {
//            Text("Next Page")
//        }
//    )
//}
