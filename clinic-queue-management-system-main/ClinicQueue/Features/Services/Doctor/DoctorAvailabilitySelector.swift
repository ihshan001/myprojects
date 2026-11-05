//
//  DoctorAvailabilitySelector.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-09.
//

import SwiftUI

struct DoctorAvailabilitySelector: View {

    let items: [DoctorAvailability]
    @Binding var selectedDate: Date?

    public var calendar = Calendar.current

    public var weekGroups: [String: [DoctorAvailability]] {
        var groups: [String: [DoctorAvailability]] = [:]

        for item in items {
            guard let weekOfMonth = calendar.dateComponents([.weekOfMonth], from: item.date).weekOfMonth else { continue }
            let key = "Week \(weekOfMonth)"
            if groups[key] == nil { groups[key] = [] }
            groups[key]?.append(item)
        }
        return groups
    }

    private func dayString(from date: Date) -> String {
        if calendar.isDateInToday(date) {
            return "Today"
        }
        let formatter = DateFormatter()
        formatter.dateFormat = "EEE d"
        return formatter.string(from: date)
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            Text("Available Dates")
                .font(.system(size: 20, weight: .bold))

            ScrollView {
                ForEach(weekGroups.keys.sorted(), id: \.self) { weekKey in
                    VStack(alignment: .leading, spacing: 8) {
                        Text(weekKey)
                            .font(.headline)
                            .padding(.vertical, 4)

                        ForEach(weekGroups[weekKey] ?? []) { item in
                            HStack {
                                RadioOptionRow(
                                    id: item.id.uuidString,
                                    title: dayString(from: item.date),
                                    subtitle: item.timeRange,
                                    icon: item.icon ?? Image(systemName: "calendar"),
                                    selectedId: Binding(
                                        get: { selectedDate == item.date ? item.id.uuidString : nil },
                                        set: { _ in selectedDate = item.date }
                                    )
                                )
                                Spacer()
                            }
                        }
                    }
                    .padding(.bottom, 10)
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
       
    }
}

// Preview
#Preview {
    DoctorAvailabilitySelector(
        items: [
            DoctorAvailability(
                weekday: "Mon",
                timeRange: "9:00–12:00",
                icon: Image(systemName: "calendar"),
                queueLabel: "Q1",
                date: Date()
            ),
            DoctorAvailability(
                weekday: "Wed",
                timeRange: "13:00–16:00",
                icon: Image(systemName: "calendar"),
                queueLabel: "Q2",
                date: Calendar.current.date(byAdding: .day, value: 2, to: Date())!
            ),
            DoctorAvailability(
                weekday: "Thu",
                timeRange: "10:00–14:00",
                icon: Image(systemName: "calendar"),
                queueLabel: "Q3",
                date: Calendar.current.date(byAdding: .day, value: 8, to: Date())!
            )
        ],
        selectedDate: .constant(nil)
    )
}
