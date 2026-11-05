//
//  DoctorAppointmentStarterView.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-09.
//

import SwiftUI

struct DoctorAppointmentStarterView: View {

    @EnvironmentObject var sessionManager: SessionManager
    @EnvironmentObject var session: SessionManagerV2

    let doctor: InfoCardData

    @State private var selectedQueue: UUID? = nil
    @State private var navigateToPaymentView = false
    @State private var selectedPaymentOption: String? = "card"


    private var availableQueues: [QueueOption] {

        guard let queueString = doctor.activeQueueCount else { return [] }

        let currentQueue = Int(queueString
            .components(separatedBy: " ")
            .first ?? "0") ?? 0

        let start = min(currentQueue + 1, 20)

        return (start...20).map { index in
            QueueOption(
                heading: String(format: "%02d", index),
                subText: "~15 min"
            )
        }
    }


    private var paymentDetailsData: [PaymentDetailRow] {

        let consultationFee = Double(
            doctor.price?.replacingOccurrences(of: "$", with: "") ?? "0"
        ) ?? 0

        let adminFee = PaymentConfig.adminFee
        let total = consultationFee + adminFee - PaymentConfig.additionalDiscount

        return [
            PaymentDetailRow(label: "Consultation", value: "$\(String(format: "%.2f", consultationFee))"),
            PaymentDetailRow(label: "Admin Fee", value: "$\(String(format: "%.2f", adminFee))"),
            PaymentDetailRow(label: "Additional Discount", value: "$\(String(format: "%.2f", PaymentConfig.additionalDiscount))"),
            PaymentDetailRow(label: "Total", value: "$\(String(format: "%.2f", total))")
        ]
    }

    private let paymentOptionsData: [CheckboxItem] = [
        CheckboxItem(key: "card", label: "Card Payment", icon: Image("Card")),
        CheckboxItem(key: "cash", label: "Cash Payment", icon: Image("Cash"))
    ]

    var body: some View {

        NavigationStack {

            ZStack {

                ScrollView {

                    VStack(alignment: .leading, spacing: 20) {

                        Text("Your Appointment")
                            .font(.system(size: 20, weight: .bold))
                            .frame(maxWidth: .infinity, alignment: .center)
                            .padding(.horizontal)

                        InfoCard(data: doctor)
                            .padding(.horizontal)
                            .padding(.top, Spacing.section)


                        VStack(alignment: .leading, spacing: 12) {

                            Text("Available Queues")
                                .font(.system(size: 16, weight: .semibold))
                                .frame(maxWidth: .infinity, alignment: .center)

                            QueueButtonGroup(
                                queues: availableQueues,
                                selectedId: $selectedQueue
                            )
                            .padding(.horizontal)

                        }
                        .padding(.top, Spacing.section)

                        PaymentDetails(rows: paymentDetailsData)
                            .padding(.horizontal)
                            .padding(.top, Spacing.section)


                        PaymentOptions(
                            items: paymentOptionsData,
                            selectedKey: $selectedPaymentOption
                        )
                        .padding(.horizontal)
                        .padding(.top, Spacing.section)
                        .padding(.bottom, 80)

                    }
                    .padding(.vertical, 20)
                }

                VStack {

                    Spacer()

                    VStack {

                        PrimaryButton(title: "Book Appointment") {
                            let fee = Double(doctor.price?.replacingOccurrences(of: "$", with: "") ?? "0") ?? 0

                            
                            if sessionManager.currentClinicVisit == nil {
                                var newVisit = ClinicVisit(
                                    patientName: "Patient",
                                    age: 0,
                                    gender: ""
                                )
                                newVisit.consultationFee = fee
                                newVisit.adminFee = PaymentConfig.adminFee
                                sessionManager.currentClinicVisit = newVisit
                            } else {
                                var visit = sessionManager.currentClinicVisit!
                                visit.consultationFee = fee
                                visit.adminFee = PaymentConfig.adminFee
                                sessionManager.currentClinicVisit = visit
                            }

                   
                            if let index = session.activities.firstIndex(where: {
                                $0.service == .appointment && $0.isSelected
                            }) {
                                session.activities[index].stage = .planning
                            }

                            navigateToPaymentView = true
                        }

                    }
                    .padding(20)
                    .background(Color(UIColor.systemBackground))
                    .shadow(color: Color.black.opacity(0.05), radius: 5, x: 0, y: -5)
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottom)

            }

            .navigationDestination(isPresented: $navigateToPaymentView) {

                if selectedPaymentOption == "card" {

                    if let currentVisit = sessionManager.currentClinicVisit {

                        PaymentView(
                            onPaymentSuccess: {
                                PaymentStatusView(
                                    isSuccess: true,
                                    doctor: doctor,
                                    queue: availableQueues.first(where: { $0.id == selectedQueue }),
                                    onContinue: { ChannelingHistoryView() },
                                    currentVisit: sessionManager.currentClinicVisit
                                )
                            },
                            currentVisit: Binding(
                                get: { currentVisit },
                                set: { sessionManager.currentClinicVisit = $0 }
                            )
                        )

                    } else {
                        Text("No current visit found")
                    }

                } else {

                    PaymentThroughCashView {
                        PaymentStatusView(
                            isSuccess: true,
                            doctor: doctor,
                            queue: availableQueues.first(where: { $0.id == selectedQueue }),
                            onContinue: { ChannelingHistoryView() },
                            currentVisit: sessionManager.currentClinicVisit
                        )
                    }

                }

            }
        }
    }
}
