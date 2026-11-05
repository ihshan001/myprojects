//
//  AppointmentStarterView.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-07.
//

import SwiftUI

struct AppointmentStarterView: View {
    @EnvironmentObject var sessionManager: SessionManager
    @EnvironmentObject var session: SessionManagerV2
    @State private var selectedQueue: UUID? = nil
    @State private var navigateToPaymentView = false

    
    private var activeActivity: Activity? {
        session.activities.first(where: {
            $0.service == session.currentService && $0.isSelected && $0.queueStage != .cancel
        })
    }

    private var doctorInfo: InfoCardData? {
        guard let doctor = activeActivity?.selectedDoctor else { return nil }

        return InfoCardData(
            image: doctor.image,
            heading: doctor.heading,
            subheading: doctor.subheading,
            activeQueueCount: doctor.activeQueueCount,
            detail1: ("Location:", doctor.detail2?.1 ?? "N/A"),
            price: doctor.price,
            availableDates: doctor.availableDates,
            isPriceButtonVisble: doctor.isPriceButtonVisble
        )
    }

    private var nextAvailableQueue: QueueOption? {

        guard let activity = activeActivity else { return nil }

        if let doctor = activity.selectedDoctor {

            let currentQueue = Int(
                doctor.activeQueueCount?
                    .components(separatedBy: " ")
                    .first ?? "0"
            ) ?? 0

            let nextSlotIndex = currentQueue + 1

            return QueueOption(
                heading: String(format: "%02d", nextSlotIndex),
                subText:  "~15 min",
                
            )
        }

        if activity.labStep != nil || activity.imagingStep != nil {

            return QueueOption(
                heading: "12",
                subText: activity.labStep?.estimatedWait
                    ?? activity.imagingStep?.estimatedWait
                    ?? "~10 min"
            )
        }

        return nil
    }

    private var paymentDetailsData: [PaymentDetailRow] {

        guard let activity = activeActivity else { return [] }

        if let doctor = activity.selectedDoctor {

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

        else if let lab = activity.labStep {

            let fee = lab.price ?? 0
            let adminFee = PaymentConfig.adminFee
            let total = fee + adminFee - PaymentConfig.additionalDiscount

            return [
                PaymentDetailRow(label: lab.name, value: "$\(String(format: "%.2f", fee))"),
                PaymentDetailRow(label: "Admin Fee", value: "$\(String(format: "%.2f", adminFee))"),
                PaymentDetailRow(label: "Additional Discount", value: "$\(String(format: "%.2f", PaymentConfig.additionalDiscount))"),
                PaymentDetailRow(label: "Total", value: "$\(String(format: "%.2f", total))")
            ]
        }

        else if let imaging = activity.imagingStep {

            let fee = imaging.price ?? 0
            let adminFee = PaymentConfig.adminFee
            let total = fee + adminFee - PaymentConfig.additionalDiscount

            return [
                PaymentDetailRow(label: imaging.name, value: "$\(String(format: "%.2f", fee))"),
                PaymentDetailRow(label: "Admin Fee", value: "$\(String(format: "%.2f", adminFee))"),
                PaymentDetailRow(label: "Additional Discount", value: "$\(String(format: "%.2f", PaymentConfig.additionalDiscount))"),
                PaymentDetailRow(label: "Total", value: "$\(String(format: "%.2f", total))")
            ]
        }

        return []
    }

    private let paymentOptionsData: [CheckboxItem] = [
        CheckboxItem(key: "card", label: "Card Payment", icon: Image("Card")),
        CheckboxItem(key: "cash", label: "Cash Payment", icon: Image("Cash"))
    ]

    @State private var selectedPaymentOption: String? = "card"

    var body: some View {
        NavigationStack {
            ZStack {
                ScrollView {
                    VStack(alignment: .leading, spacing: 20) {

                        Text("Your Clinic Queue")
                            .font(.system(size: 20, weight: .bold))
                            .frame(maxWidth: .infinity, alignment: .center)
                            .padding(.horizontal)

                        if let doctor = doctorInfo {
                            InfoCard(data: doctor)
                                .padding(.horizontal)
                                .padding(.top, Spacing.section)
                        }

                        if let activity = activeActivity {

                            if activity.labStep != nil {
                                BloodTestCard(
                                    image: TestDataset.imageName(for: activity.labStep!.name ?? ""),
                                    title: activity.labStep!.name,
                                    specialText: "",
                                    detailLine1: "Location: \(activity.labStep?.location ?? "N/A")",
                                    detailLine2: "",
                                    showExtraSection: true,
                                    bottomTitleLeft: "Requirements",
                                    listItems: activity.labStep?.requirements ?? [],
                                    bottomTitleRight: "Approximate Time",
                                    bottomSubTextRight: activity.labStep?.estimatedWait ?? "",
                                    fee: activity.labStep?.price != nil ? "$\(Int(activity.labStep!.price!))" : "Free",
                                    isActiveQueue: false
                                )
                                .padding(.horizontal)
                            }

                            if activity.imagingStep != nil {
                                BloodTestCard(
                                    image: TestDataset.imageName(for: activity.imagingStep!.name ?? ""),
                                    title: activity.imagingStep!.name,
                                    specialText: "",
                                    detailLine1: "Location: \(activity.imagingStep?.location ?? "N/A")",
                                    detailLine2: "",
                                    showExtraSection: true,
                                    bottomTitleLeft: "Requirements",
                                    listItems: activity.imagingStep?.requirements ?? [],
                                    bottomTitleRight: "Approximate Time",
                                    bottomSubTextRight: activity.imagingStep?.estimatedWait ?? "",
                                    fee: activity.imagingStep?.price != nil ? "$\(Int(activity.imagingStep!.price!))" : "Free",
                                    isActiveQueue: false
                                )
                                .padding(.horizontal)
                            }
                        }

                        VStack(alignment: .leading, spacing: 12) {

                            Text("Available Queues")
                                .font(.system(size: 16, weight: .semibold))
                                .frame(maxWidth: .infinity, alignment: .center)

                            if let queue = nextAvailableQueue {
                                QueueButtonGroup(queues: [queue], selectedId: $selectedQueue)
                                    .padding(.horizontal)
                            }
                        }
                        .padding(.top, Spacing.section)

                        PaymentDetails(rows: paymentDetailsData)
                            .padding(.horizontal)
                            .padding(.top, Spacing.section)

                        PaymentOptions(items: paymentOptionsData, selectedKey: $selectedPaymentOption)
                            .padding(.horizontal)
                            .padding(.top, Spacing.section)
                            .padding(.bottom, 80)

                    }
                    .padding(.vertical, 20)
                    .onAppear {
                        if activeActivity == nil {
                            session.addActivity(service: session.currentService)
                        }
                    }
                }
                .navigationDestination(isPresented: $navigateToPaymentView) {

                    if selectedPaymentOption == "card" {

                        if let currentVisit = sessionManager.currentClinicVisit {

                            PaymentView(
                                onPaymentSuccess: {
                                    PaymentStatusView(
                                        isSuccess: true,
                                        doctor: activeActivity?.selectedDoctor,
                                        queue: nextAvailableQueue,
                                        onContinue: { Queue() },
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
                                  doctor: activeActivity?.selectedDoctor,
                                  queue: nextAvailableQueue,
                                  onContinue: { Queue() },
                                  currentVisit: sessionManager.currentClinicVisit
                              )
                          }
                    }
                }

                VStack {
                    Spacer()

                    VStack {
                        PrimaryButton(title: "Book Appointment") {

                            
                            if sessionManager.currentClinicVisit == nil {
                                var tempVisit = ClinicVisit(patientName: "Patient", age: 0, gender: "")
                                if let lab = activeActivity?.labStep { tempVisit.consultationFee = lab.price ?? 0 }
                                if let imaging = activeActivity?.imagingStep { tempVisit.consultationFee = imaging.price ?? 0 }
                                tempVisit.adminFee = PaymentConfig.adminFee
                                sessionManager.currentClinicVisit = tempVisit
                            }

                            if var visit = sessionManager.currentClinicVisit {

                                if let doctor = activeActivity?.selectedDoctor {
                                    let fee = Double(
                                        doctor.price?.replacingOccurrences(of: "$", with: "") ?? "0"
                                    ) ?? 0
                                    visit.consultationFee = fee
                                }

                                if let lab = activeActivity?.labStep {
                                    visit.consultationFee = lab.price ?? 0
                                }

                                if let imaging = activeActivity?.imagingStep {
                                    visit.consultationFee = imaging.price ?? 0
                                }

                                visit.adminFee = PaymentConfig.adminFee
                                sessionManager.currentClinicVisit = visit
                            }

                            navigateToPaymentView = true
                        }
                    }
                    .padding(20)
                    .background(Color(UIColor.systemBackground))
                    .shadow(color: Color.black.opacity(0.05), radius: 5, x: 0, y: -5)
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottom)

                FloatingNav(
                    mainIcon: "plus",
                    items: [
                        FloatingNavItem(icon: "house.fill", label: "Home", destination: AnyView(ServicesView())),
                        FloatingNavItem(icon: "map.fill", label: "Map", destination: AnyView(Text("Map View"))),
                        FloatingNavItem(icon: "gearshape.fill", label: "Settings", destination: AnyView(SettingsView()))
                    ]
                )
            }
        }
    }
}

#Preview {
    AppointmentStarterView()
}
