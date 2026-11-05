//
//  ImagingDetailsView.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-11.
//

import SwiftUI

struct ImagingDetailsView: View {
    let selectedTests: [ClinicStep]
    var backgroundColor: Color = AppColors.primary

    @EnvironmentObject var sessionManager: SessionManager
    @State private var selectedPaymentOption: String? = "card"
    @State private var navigateToPaymentView = false

    private var totalPrice: Double {
        selectedTests.reduce(0) { $0 + ($1.price ?? 0) }
    }

    private var adminFee: Double {
        PaymentConfig.adminFee
    }

    private var totalPayment: Double {
        totalPrice + adminFee - PaymentConfig.additionalDiscount
    }

    private var paymentDetailsData: [PaymentDetailRow] {
        [
            PaymentDetailRow(label: "Imaging Tests",        value: "$ \(String(format: "%.2f", totalPrice))"),
            PaymentDetailRow(label: "Admin Fee",            value: "$ \(String(format: "%.2f", adminFee))"),
            PaymentDetailRow(label: "Additional Discount",  value: "$ \(String(format: "%.2f", PaymentConfig.additionalDiscount))"),
            PaymentDetailRow(label: "Total",                value: "$ \(String(format: "%.2f", totalPayment))")
        ]
    }

    private let paymentOptionsData: [CheckboxItem] = [
        CheckboxItem(key: "card", label: "Card Payment", icon: Image("Card")),
        CheckboxItem(key: "cash", label: "Cash Payment", icon: Image("Cash"))
    ]

    private var visitBinding: Binding<ClinicVisit> {
        Binding(
            get: { sessionManager.currentClinicVisit ?? ClinicVisit(patientName: "", age: 0, gender: "") },
            set: { sessionManager.currentClinicVisit = $0 }
        )
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 24) {

                Text("Imaging Tests")
                    .font(.system(size: 20, weight: .bold))
                    .frame(maxWidth: .infinity, alignment: .center)
                    .padding(.horizontal)

                ForEach(Array(selectedTests.enumerated()), id: \.element.id) { index, test in
                    VStack(alignment: .leading, spacing: 16) {

                        BloodTestCard(
                            image: TestDataset.imageName(for: test.name),
                            title: test.name,
                            specialText: "Available Now",
                            detailLine1: "Location: \(test.location ?? "")",
                            detailLine2: "Wait: \(test.estimatedWait ?? "")",
                            showExtraSection: false,
                            fee: "LKR \(test.price ?? 0)",
                            isCheckboxSelectable: false
                        )

                        if index < selectedTests.count - 1 {
                            Divider().padding(.vertical, 8)
                        }
                    }
                }

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
                    PrimaryButton(title: "Booking", maxWidth: 320) {
                        if var visit = sessionManager.currentClinicVisit {
                            visit.consultationFee = totalPrice
                            visit.adminFee        = adminFee
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
        }
        .navigationDestination(isPresented: $navigateToPaymentView) {
            if selectedPaymentOption == "card" {
                PaymentView(
                    onPaymentSuccess: {
                        PaymentStatusView(
                            isSuccess: true,
                            doctor: nil,
                            queue: nil,
                         
                            onContinue: { LabQueueTrackerView(steps: selectedTests) },
                            currentVisit: sessionManager.currentClinicVisit
                        )
                    },
                    currentVisit: visitBinding
                )
            } else {
                PaymentThroughCashView {
                    PaymentStatusView(
                        isSuccess: true,
                        doctor: nil,
                        queue: nil,
                     
                        onContinue: { LabQueueTrackerView(steps: selectedTests) },
                        currentVisit: sessionManager.currentClinicVisit
                    )
                }
            }
        }
    }
}

#Preview {
    NavigationStack {
        ImagingDetailsView(selectedTests: [
            ClinicStep(
                type: .imaging,
                name: "Chest X-Ray (PA & Lateral)",
                description: "A quick, non-invasive imaging test.",
                estimatedWait: "~15 min",
                price: 60,
                location: "Radiology - Level 1, Wing A",
                requirements: ["Remove all jewelry", "Wear loose-fitting clothing"]
            )
        ])
        .environmentObject(SessionManager())
    }
}
