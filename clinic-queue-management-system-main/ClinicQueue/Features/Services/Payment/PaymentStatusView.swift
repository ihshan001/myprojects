//
//  PaymentStatusView.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-08.
//

import SwiftUI

struct PaymentStatusView<NextDestination: View>: View {
    let isSuccess: Bool
    let doctor: InfoCardData?
    let queue: QueueOption?
    let onContinue: () -> NextDestination
    let currentVisit: ClinicVisit?

    @State private var navigateNext = false
    @State private var navigateToChanneling = false
    @State private var showRefundModal = false
    @State private var showCashRefundQR = false
    @EnvironmentObject var session: SessionManagerV2
    @EnvironmentObject var sessionManager: SessionManager

    private var paymentDetailsData: [PaymentDetailRow] {
        guard let visit = sessionManager.currentClinicVisit else { return [] }

        return [
            PaymentDetailRow(label: "Consultation", value: "$\(String(format: "%.2f", visit.consultationFee ?? 0))"),
            PaymentDetailRow(label: "Admin Fee", value: "$\(String(format: "%.2f", visit.adminFee ?? 0))"),
            PaymentDetailRow(label: "Additional Discount", value: "$\(String(format: "%.2f", PaymentConfig.additionalDiscount))"),
            PaymentDetailRow(label: "Total", value: "$\(String(format: "%.2f", visit.totalPayment))")
        ]
    }

    var body: some View {
        NavigationStack {
            ZStack {
                ScrollView {
                    VStack(spacing: 20) {
                        Text(isSuccess ? "Payment Successful" : "Payment Failed")
                            .font(.title2)
                            .fontWeight(.bold)

                        Image(systemName: isSuccess ? "checkmark.circle.fill" : "xmark.circle.fill")
                            .font(.system(size: 70))
                            .foregroundColor(isSuccess ? .green : .red)

                        Text(
                            isSuccess
                            ? "Your payment has been received."
                            : "Something went wrong while processing your payment."
                        )
                        .foregroundColor(AppColors.lableColor)
                        .multilineTextAlignment(.center)
                        .padding(.horizontal)

                        PaymentDetails(rows: paymentDetailsData)
                            .padding(.horizontal)
                            .padding(.top, Spacing.section)

                        PrimaryButton(title: isSuccess ? "Continue" : "Try Again") {
                            navigateNext = true
                        }

                        if isSuccess {
                            Button("Cancel & Refund") {
                                showRefundModal = true
                            }
                            .foregroundColor(.red)
                            .font(.system(size: 15, weight: .medium))
                            .padding(.top, 4)
                            .padding(.bottom, 40)
                        }
                    }
                    .padding()
                    .navigationBarBackButtonHidden(true)
                    .onAppear {
                        guard isSuccess else { return }
                        handlePaymentSuccess()
                        session.printAllActivities()
                    }
                }

                FloatingNav(
                    mainIcon: "plus",
                    items: [
                        FloatingNavItem(icon: "house.fill", label: "Home", destination: AnyView(ServicesView())),
                        FloatingNavItem(icon: "map.fill", label: "Map", destination: AnyView(Text("Map View"))),
                        FloatingNavItem(icon: "gearshape.fill", label: "Settings", destination: AnyView(SettingsView()))
                    ]
                ).padding(.top, 320)

                // Refund confirmation modal
                CustomModal(isPresented: $showRefundModal) {
                    VStack(spacing: 16) {
                        Image(systemName: "arrow.uturn.backward.circle.fill")
                            .font(.system(size: 44))
                            .foregroundColor(.orange)

                        Text("Cancel & Refund")
                            .font(.system(size: 18, weight: .bold))

                        Text("Are you sure you want to cancel and request a refund? This action cannot be undone.")
                            .font(.system(size: 14))
                            .foregroundColor(.secondary)
                            .multilineTextAlignment(.center)

                        HStack(spacing: 12) {
                            Button("No, Keep It") {
                                showRefundModal = false
                            }
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 12)
                            .background(Color(UIColor.systemGray5))
                            .foregroundColor(.primary)
                            .cornerRadius(10)

                            Button("Yes, Refund") {
                                showRefundModal = false
                                performRefund()
                                showCashRefundQR = true
                            }
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 12)
                            .background(Color.red)
                            .foregroundColor(.white)
                            .cornerRadius(10)
                        }
                    }
                }

                // Cash refund QR sheet — auto-closes after 5 min then navigates to My Channelings
                if showCashRefundQR {
                    CashRefundSheet(isPresented: $showCashRefundQR) {
                        navigateToChanneling = true
                    }
                }
            }
            .navigationDestination(isPresented: $navigateNext) {
                onContinue()
            }
            .navigationDestination(isPresented: $navigateToChanneling) {
                ChannelingHistoryView()
            }
        }
    }

    // MARK: - Payment Logic

    private func handlePaymentSuccess() {
        if session.currentService == .appointment {
            // doctor == nil means this is a test payment inside the appointment flow
            if doctor == nil {
                if let idx = session.activities.firstIndex(where: {
                    $0.service == .appointment && $0.isSelected && $0.queueStage != .cancel
                }) {
                    session.activities[idx].queueStage = .wait
                    session.activities[idx].stage = .inQueue
                }
            } else {
                // Initial appointment booking — save to history
                let allAppointmentActivities = session.activities.filter { $0.service == .appointment }
                if let mainActivity = allAppointmentActivities.first(where: { $0.isSelected }) {
                    let fee = Double(doctor?.price?.replacingOccurrences(of: "$", with: "") ?? "0") ?? 0
                    let apptId = UUID()
                    let appointment = UpcomingAppointment(
                        id: apptId,
                        date: mainActivity.appointmentDate ?? Date(),
                        patientName: mainActivity.patientName ?? "Patient",
                        age: mainActivity.patientAge ?? 0,
                        gender: mainActivity.patientGender ?? "",
                        activities: allAppointmentActivities,
                        totalFee: fee + PaymentConfig.adminFee
                    )
                    session.upcomingAppointments.append(appointment)
                    session.currentAppointmentId = apptId
                    session.activities.removeAll { $0.service == .appointment }
                }
            }
            return
        }

        // CLINIC FLOW
        guard var visit = sessionManager.currentClinicVisit else { return }

        if let doctor = doctor {
            var doctorStep = visit.doctorStep ?? ClinicStep(type: .doctor, name: doctor.heading)
            doctorStep.name = doctor.heading
            doctorStep.specialty = doctor.subheading
            doctorStep.queueNumber = queue?.heading
            if let priceString = doctor.price?.replacingOccurrences(of: "$", with: "") {
                doctorStep.price = Double(priceString) ?? 0
            } else {
                doctorStep.price = 0
            }
            visit.updateStep(doctorStep)
            visit.consultationFee = doctorStep.price
            visit.adminFee = PaymentConfig.adminFee
            sessionManager.currentClinicVisit = visit
        }

        if let selectedActivityIndex = session.activities.firstIndex(where: { activity in
            activity.service == .clinic && activity.isSelected
        }) {
            session.activities[selectedActivityIndex].queueStage = .wait
            session.activities[selectedActivityIndex].stage = .inQueue
        }
    }

    private func performRefund() {
        if session.currentService == .appointment {
            session.refundCurrentAppointment()
            session.activities.removeAll { $0.service == .appointment }
            session.currentAppointmentId = nil
        } else {
            session.activities.removeAll { $0.service == .clinic }
            session.hasRejoinedDoctor = false
        }
        sessionManager.currentClinicVisit = nil
    }
}
