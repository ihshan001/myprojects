//
//  QueueButtonGroup.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-07.
//

import SwiftUI

struct QueueOption: Identifiable {
    let id = UUID()
    let heading: String
    let subText: String
}

struct QueueButtonGroup: View {
    let queues: [QueueOption]
    @Binding var selectedId: UUID?

    private let columns = [
        GridItem(.adaptive(minimum: 96), spacing: 10)
    ]

    var body: some View {
        Group {
            if queues.count == 1 {
                HStack {
                    Spacer()
                    queueButton(for: queues.first!)
                    Spacer()
                }
            } else {
                LazyVGrid(columns: columns, spacing: 10) {
                    ForEach(queues) { queue in
                        queueButton(for: queue)
                    }
                }
            }
        }
        .animation(.easeInOut, value: selectedId)
        .onAppear {
            if selectedId == nil {
                selectedId = queues.first?.id
            }
        }
    }

    private func queueButton(for queue: QueueOption) -> some View {
        let isSelected = selectedId == queue.id

        return Button {
            selectedId = queue.id
        } label: {
            VStack(spacing: 6) {
                Text(queue.heading)
                    .font(.system(size: 20, weight: .bold))
                    .foregroundColor(isSelected ? .white : .black)

                Text(queue.subText)
                    .font(.system(size: 12))
                    .foregroundColor(isSelected ? .white : AppColors.text)
            }
            .frame(minWidth: 96, minHeight: 76)
            .background(
                RoundedRectangle(cornerRadius: 10)
                    .fill(isSelected ? AppColors.primary : .white)
            )
            .overlay(
                RoundedRectangle(cornerRadius: 10)
                    .stroke(
                        isSelected ? Color(hex: "157979") : Color(hex: "ECF5F5"),
                        lineWidth: 2
                    )
            )
        }
    }
}
