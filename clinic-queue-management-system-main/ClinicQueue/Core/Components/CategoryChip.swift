//
//  Untitled 3.swift
//  ClinicQueue
//
//  Created by Roshan on 2026-03-12.
//


import SwiftUI

struct CategoryChip: View {
    let label: String
    let icon: String
    @Binding var selected: String

    var isSelected: Bool { selected == label }

    var body: some View {
        Button {
            withAnimation(.easeInOut(duration: 0.2)) { selected = label }
        } label: {
            HStack(spacing: 6) {
                Image(systemName: icon)
                    .font(.system(size: 13))
                Text(label)
                    .font(.system(size: 13, weight: .semibold))
            }
            .foregroundColor(isSelected ? .white : Color(hex: "1A2E44"))
            .padding(.horizontal, 14)
            .padding(.vertical, 8)
            .background(isSelected ? Color(hex: "0DC8A4") : Color(.systemGray6))
            .cornerRadius(20)
        }
    }
}
