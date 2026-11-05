//
//  PharPayment.swift
//  ClinicQueue
//
//  Created by Roshan on 2026-03-12.
//




import SwiftUI

struct PharPayment: View {
   
    var backgroundColor: Color = AppColors.primary

    @EnvironmentObject var sessionManager: SessionManager
    @State private var selectedPaymentOption: String? = "card"
    @State private var navigateToPaymentView = false

 


    private var paymentDetailsData: [PaymentDetailRow] {
        [
            PaymentDetailRow(label: "Imaging Tests",        value: "$ 50.00"),
            PaymentDetailRow(label: "Admin Fee",            value: "$ 1.00"),
            PaymentDetailRow(label: "Additional Discount",  value: "$ \(String(format: "00.00", PaymentConfig.additionalDiscount))"),
            PaymentDetailRow(label: "Total",                value: "$ 51.000")
        ]
    }

    private let paymentOptionsData: [CheckboxItem] = [
        CheckboxItem(key: "card", label: "Card Payment", icon: Image("Card")),
        CheckboxItem(key: "cash", label: "Cash Payment", icon: Image("Cash"))
    ]

    

   

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 24) {

                Text("Payment")
                    .font(.system(size: 20, weight: .bold))
                    .frame(maxWidth: .infinity, alignment: .center)
                    .padding(.horizontal)

             

                PaymentDetails(rows: paymentDetailsData)
                    .padding(.top, Spacing.section)
                    .padding(.horizontal, 10)

                PaymentOptions(
                    items: paymentOptionsData,
                    selectedKey: $selectedPaymentOption
                    
                )
                
                .padding(.top, Spacing.section)
                .padding(.horizontal, 10)
              
                HStack {
                    PrimaryButton(title: "Booking", maxWidth: 300) {
                        if var visit = sessionManager.currentClinicVisit {
                            
                            sessionManager.currentClinicVisit = visit
                        }
                        navigateToPaymentView = true
                    }
                    .frame(maxWidth: .infinity, alignment: .center)
                }
                .padding(.top, 2)
                .padding(.bottom, 140)
            }
         
            .padding(.horizontal, 2)
        }   .navigationDestination(isPresented: $navigateToPaymentView) {
            if selectedPaymentOption == "card" {
                PharPayemntForm()
                    .environmentObject(sessionManager)
            } else {
//                PaymentThroughCashView()
//                    .environmentObject(sessionManager)
            }
        }
        
    }
}


#Preview {
    PharPayment()
        .environmentObject(SessionManager())
}
