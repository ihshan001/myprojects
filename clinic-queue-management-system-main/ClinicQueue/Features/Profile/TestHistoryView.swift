//
//  TestHistoryView.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-11.
//

import SwiftUI

enum TestStatus {
    case completed
    case rescheduled
    case pending

    var label: String {
        switch self {
        case .completed:   return "Completed"
        case .rescheduled: return "Rescheduled"
        case .pending:     return "Pending"
        }
    }

    var color: Color {
        switch self {
        case .completed:   return .green
        case .rescheduled: return .orange
        case .pending:     return .blue
        }
    }
}

struct TestHistoryItem: Identifiable {
    let id = UUID()
    let testName: String
    let doctorName: String
    let date: String
    let total: String
    let status: TestStatus
}

struct TestHistoryView: View {

    @Environment(\.presentationMode) var presentationMode

    let items: [TestHistoryItem] = [
        TestHistoryItem(
            testName: "Complete Blood Count (CBC)",
            doctorName: "Dr. Marcus Horizon",
            date: "2026/02/04 at 01:55PM",
            total: "$60.00",
            status: .completed
        ),
        TestHistoryItem(
            testName: "Fasting Blood Glucose",
            doctorName: "Dr. Marcus Horizon",
            date: "2026/02/04 at 01:55PM",
            total: "$60.00",
            status: .rescheduled
        ),
        TestHistoryItem(
            testName: "Lipid Panel",
            doctorName: "Dr. Sarah Nolan",
            date: "2026/02/20 at 10:30AM",
            total: "$85.00",
            status: .completed
        ),
        TestHistoryItem(
            testName: "Kidney Function Test",
            doctorName: "Dr. Sarah Nolan",
            date: "2026/03/15 at 09:00AM",
            total: "$120.00",
            status: .pending
        ),
    ]

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 0) {
                    ForEach(items) { item in
                        TestHistoryRow(item: item)
                        Divider()
                            .padding(.horizontal)
                    }
                }
                .background(Color.white)
                .cornerRadius(12)
                .padding(.horizontal)
                .padding(.top, 8)
            }
            .background(Color(white: 0.96))
            .navigationTitle("My Tests")
            .navigationBarTitleDisplayMode(.inline)
            
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
}

struct TestHistoryRow: View {
    let item: TestHistoryItem

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 4) {
                    Text(item.testName)
                        .font(.system(size: 15, weight: .bold))
                        .foregroundColor(.primary)

                    Text(item.doctorName)
                        .font(.system(size: 13))
                        .foregroundColor(.blue)

                    Text(item.date)
                        .font(.system(size: 12))
                        .foregroundColor(.gray)
                }

                Spacer()

                VStack(alignment: .trailing, spacing: 8) {
                    Text("Total: \(item.total)")
                        .font(.system(size: 13, weight: .semibold))
                        .foregroundColor(.primary)

                   
                    .font(.system(size: 13))
                    .foregroundColor(.gray)
                }
            }

            // Status badge
            Text(item.status.label)
                .font(.system(size: 12, weight: .semibold))
                .foregroundColor(.white)
                .padding(.horizontal, 12)
                .padding(.vertical, 5)
                .background(item.status.color)
                .cornerRadius(20)
        }
        .padding(.horizontal)
        .padding(.vertical, 14)
    }
}

#Preview {
    TestHistoryView()
}
