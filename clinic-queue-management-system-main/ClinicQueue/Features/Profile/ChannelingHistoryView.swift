//
//  ChannelingHistoryView.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-11.
//


import SwiftUI

// MARK: - Filter

enum ChannelingFilter: String, CaseIterable {
    case all       = "All"
    case upcoming  = "Upcoming"
    case refunded  = "Refunded"
    case completed = "Completed"
}



enum ChannelingStatus {
    case completed
    case cancelled
    case upcoming
    case refunded

    var label: String {
        switch self {
        case .completed: return "Completed"
        case .cancelled: return "Cancelled"
        case .upcoming:  return "Upcoming"
        case .refunded:  return "Refunded"
        }
    }

    var color: Color {
        switch self {
        case .completed: return .green
        case .cancelled: return .red
        case .upcoming:  return .blue
        case .refunded:  return .orange
        }
    }
}

// MARK: - Model

struct ChannelingHistory: Identifiable {
    let id = UUID()
    let doctorName: String
    let specialization: String
    let date: String
    let total: String
    let status: ChannelingStatus
    let tests: [String]
}



struct ChannelingHistoryRow: View {

    let item: ChannelingHistory

    var body: some View {

        VStack(alignment: .leading, spacing: 10) {

            VStack(alignment: .leading, spacing: 4) {

                Text(item.doctorName)
                    .font(.system(size: 15, weight: .bold))

                Text(item.specialization)
                    .font(.system(size: 13))
                    .foregroundColor(.blue)

                Text(item.date)
                    .font(.system(size: 12))
                    .foregroundColor(.gray)

                Text(item.total)
                    .font(.system(size: 13, weight: .semibold))
                    .foregroundColor(.primary)
            }

            if item.status == .refunded {
                HStack(spacing: 6) {
                    Image(systemName: "arrow.uturn.backward.circle.fill")
                        .foregroundColor(.orange)
                        .font(.system(size: 14))
                    Text("Payment refunded to original method")
                        .font(.system(size: 13))
                        .foregroundColor(.orange)
                }
                .padding(.vertical, 6)
                .padding(.horizontal, 10)
                .background(Color.orange.opacity(0.1))
                .cornerRadius(8)
            }

            if item.status == .completed && !item.tests.isEmpty {

                VStack(alignment: .leading, spacing: 4) {

                    Text("Tests Done")
                        .font(.system(size: 13, weight: .bold))

                    ForEach(item.tests, id: \.self) { test in
                        Text("• \(test)")
                            .font(.system(size: 12))
                            .foregroundColor(.gray)
                    }
                }
                .padding(.top, 4)
            }

            Text(item.status.label)
                .font(.system(size: 12, weight: .semibold))
                .foregroundColor(.white)
                .padding(.horizontal, 12)
                .padding(.vertical, 5)
                .background(item.status.color)
                .cornerRadius(20)
            
        
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(16)
        
    }
}



struct ChannelingHistoryView: View {

    @EnvironmentObject var session: SessionManagerV2
    @State private var navigateToQueue = false
    @State private var selectedFilter: ChannelingFilter = .all

    var body: some View {

        NavigationStack {

            VStack(spacing: 0) {

             
                Picker("Filter", selection: $selectedFilter) {
                    ForEach(ChannelingFilter.allCases, id: \.self) { filter in
                        Text(filter.rawValue).tag(filter)
                    }
                }
                .pickerStyle(.segmented)
                .padding(.horizontal)
                .padding(.vertical, 12)
                .background(Color(white: 0.96))

                ScrollView {

                    VStack(spacing: 25) {

                        if selectedFilter == .all || selectedFilter == .upcoming {
                            sectionView(
                                title: "Today",
                                appointments: todayAppointments(),
                                isTodaySection: true
                            )

                            sectionView(
                                title: "Upcoming",
                                appointments: upcomingAppointments(),
                                isTodaySection: false
                            )
                        }

                        if selectedFilter == .all || selectedFilter == .completed {
                            sectionView(
                                title: "Completed",
                                appointments: completedAppointments(),
                                isTodaySection: false
                            )
                        }

                        if selectedFilter == .all || selectedFilter == .refunded {
                            sectionView(
                                title: "Refunded",
                                appointments: refundedAppointments(),
                                isTodaySection: false
                            )
                        }
                    }
                    .padding(.vertical)
                }
            }
            .background(Color(white: 0.96))
            .navigationTitle("My Channelings")
            .navigationBarTitleDisplayMode(.inline)
            .onAppear {
               
                if session.upcomingAppointments.isEmpty {
                    session.upcomingAppointments = DummyAppointments.generate()
                }
            }
            .navigationDestination(isPresented: $navigateToQueue) {
                Queue()
            }
            
            
        }
        
        .overlay(
            FloatingNav(
                mainIcon: "plus",
                items: [
                    FloatingNavItem(icon: "house.fill", label: "Home", destination: AnyView(ServicesView())),
                    FloatingNavItem(icon: "map.fill", label: "Map", destination: AnyView(Text("Map View"))),
                    FloatingNavItem(icon: "gearshape.fill", label: "Settings", destination: AnyView(ProfileView()))
                ]
            ),
            alignment: .bottomTrailing
            )
    }

 

    @ViewBuilder
    private func sectionView(
        title: String,
        appointments: [UpcomingAppointment],
        isTodaySection: Bool
    ) -> some View {

        if !appointments.isEmpty {

            VStack(alignment: .leading, spacing: 12) {

                Text(title)
                    .font(.system(size: 20, weight: .bold))
                    .padding(.horizontal)

                VStack(spacing: 0) {

                    ForEach(appointments) { appointment in

                        ChannelingHistoryRow(
                            item: mapAppointment(appointment)
                        )
                        .background(Color.white)
                        .contentShape(Rectangle())
                        .onTapGesture {

                            guard isTodaySection else { return }

                            prepareQueue(from: appointment)
                            navigateToQueue = true
                        }

                        if appointment.id != appointments.last?.id {
                            Divider().padding(.leading, 16)
                        }
                    }
                }
                .background(Color.white)
                .cornerRadius(10)
                .padding(.horizontal)
            }
        }
    }

    

    private func prepareQueue(from appointment: UpcomingAppointment) {

        session.currentService = .appointment
        session.activities.removeAll { $0.service == .appointment }

        for var activity in appointment.activities {

            if activity.selectedDoctor != nil {
                activity.isSelected = true
                activity.queueStage = .wait
            }

            session.activities.append(activity)
        }
    }

   

    private func todayAppointments() -> [UpcomingAppointment] {
        session.upcomingAppointments.filter {
            !$0.isRefunded && Calendar.current.isDateInToday($0.date)
        }
    }

    private func upcomingAppointments() -> [UpcomingAppointment] {
        session.upcomingAppointments.filter {
            !$0.isRefunded &&
            $0.date > Date() &&
            !Calendar.current.isDateInToday($0.date)
        }
    }

    private func completedAppointments() -> [UpcomingAppointment] {
        session.upcomingAppointments.filter {
            !$0.isRefunded &&
            $0.date < Date() &&
            !Calendar.current.isDateInToday($0.date)
        }
    }

    private func refundedAppointments() -> [UpcomingAppointment] {
        session.upcomingAppointments.filter { $0.isRefunded }
    }

    

    private func mapAppointment(
        _ appointment: UpcomingAppointment
    ) -> ChannelingHistory {

        let doctor = appointment.activities.first?.selectedDoctor

        let status: ChannelingStatus

        if appointment.isRefunded {
            status = .refunded
        } else if appointment.date < Date() && !Calendar.current.isDateInToday(appointment.date) {
            status = .completed
        } else {
            status = .upcoming
        }

        let tests: [String]
        if status == .completed {
            let labTests = appointment.activities.compactMap { $0.labStep?.name }
            let imagingTests = appointment.activities.compactMap { $0.imagingStep?.name }
            let allTests = labTests + imagingTests
            tests = allTests.isEmpty
                ? ["Complete Blood Count (CBC)", "Chest X-Ray (PA & Lateral)"]
                : allTests
        } else {
            tests = []
        }

        return ChannelingHistory(
            doctorName: doctor?.heading ?? "Doctor",
            specialization: doctor?.subheading ?? "-",
            date: formattedDate(appointment.date),
            total: "$\(String(format: "%.2f", appointment.totalFee))",
            status: status,
            tests: tests
        )
    }

    private func formattedDate(_ date: Date) -> String {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy/MM/dd 'at' hh:mm a"
        return formatter.string(from: date)
    }
}
