//
//  TransactionHistoryView.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-11.
//

import SwiftUI

enum TransactionHistoryStatus {
    case completed
    case refunded

    var label: String {
        switch self {
        case .completed: return "Completed"
        case .refunded:  return "Refunded"
        }
    }

    var color: Color {
        switch self {
        case .completed: return .green
        case .refunded:  return .orange
        }
    }
}

struct TestHistory: Identifiable {
    let id = UUID()
    let testName: String
    let doctorName: String
    let date: String
    let total: String
    let status: TransactionHistoryStatus
}

struct TransactionHistory: View {

    @Environment(\.presentationMode) var presentationMode

    let items: [TestHistory] = [
        TestHistory(
            testName: "Doctor/Consultant Channeling",
            doctorName: "Dr. Marcus Horizon",
            date: "2026/02/04 at 01:55PM",
            total: "$60.00",
            status: .completed
        ),
        TestHistory(
            testName: "Doctor/Consultant Channeling",
            doctorName: "Dr. Marcus Horizon",
            date: "2026/02/04 at 01:55PM",
            total: "$60.00",
            status: .refunded
        ),
       
    ]

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 0) {
                    ForEach(items) { item in
                        TransactionHistoryRow(item: item)
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
            .navigationTitle("My Transactions")
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

struct TransactionHistoryRow: View {
    let item: TestHistory

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
    TransactionHistory()
}
