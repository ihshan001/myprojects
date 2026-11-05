//
//  PharStatus.swift
//  ClinicQueue
//
//  Created by Roshan on 2026-03-13.
//

import SwiftUI

struct PharStatus: View {

    @State private var navigateNext = false

    private var paymentDetailsData: [PaymentDetailRow] {
        [
            PaymentDetailRow(label: "Imaging Tests",        value: "$ 50.00"),
            PaymentDetailRow(label: "Admin Fee",            value: "$ 1.00"),
            PaymentDetailRow(label: "Additional Discount",  value: "$ \(String(format: "00.00", PaymentConfig.additionalDiscount))"),
            PaymentDetailRow(label: "Total",                value: "$ 51.000")
        ]
    }

    var body: some View {
        NavigationStack {
            ZStack {
                ScrollView {
                    VStack(spacing: 20) {
                        Text("Payment Successful")
                            .font(.title2)
                            .fontWeight(.bold)

                        Image(systemName: "checkmark.circle.fill")
                            .font(.system(size: 70))
                            .foregroundColor(.green)

                        Text("Your payment has been received.")
                            .foregroundColor(AppColors.lableColor)
                            .multilineTextAlignment(.center)
                            .padding(.horizontal)

                        PaymentDetails(rows: paymentDetailsData)
                            .padding(.horizontal)
                            .padding(.top, Spacing.section)

                        PrimaryButton(title: "Continue") {
                            navigateNext = true
                        }
                    }
                    .padding()
                    .navigationBarBackButtonHidden(true)
                    .onAppear {
                        
                    }
                }

                FloatingNav(
                    mainIcon: "plus",
                    items: [
                        FloatingNavItem(icon: "house.fill", label: "Home", destination: AnyView(ServicesView())),
                        FloatingNavItem(icon: "map.fill", label: "Map", destination: AnyView(Text("Map View"))),
                        FloatingNavItem(icon: "gearshape.fill", label: "Settings", destination: AnyView(SettingsView()))
                    ]
                )
            }
            .navigationDestination(isPresented: $navigateNext) {
                PharmacyView()
            }
        }
    }
}

#Preview {
    PharStatus()
}
