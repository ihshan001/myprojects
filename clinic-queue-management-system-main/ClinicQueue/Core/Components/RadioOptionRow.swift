//
//  RadioOptionRow.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-09.
//

import SwiftUI

struct RadioOptionRow: View {

    let id: String
    let title: String
    let subtitle: String?
    let icon: Image?

    @Binding var selectedId: String?

    var body: some View {

        Button {
            selectedId = id
        } label: {

            HStack {

   
                HStack(spacing: 10) {

                    if let icon = icon {
                        icon
                            .resizable()
                            .scaledToFit()
                            .frame(width: 15, height: 15)
                    }

                    Text(title)
                        .font(.system(size: 12, weight: .medium))
                        .foregroundColor(AppColors.textdark)
                }

                Spacer()


                if let subtitle = subtitle {
                    Text(subtitle)
                        .font(.system(size: 12))
                        .foregroundColor(AppColors.text)
                }

                Spacer()


                ZStack {

                    Circle()
                        .stroke(
                            selectedId == id ? AppColors.primary : Color.gray.opacity(0.4),
                            lineWidth: 2
                        )
                        .frame(width: 18, height: 18)

                    if selectedId == id {
                        Circle()
                            .fill(AppColors.primary)
                            .frame(width: 10, height: 10)
                    }
                }
            }
            .padding(.vertical, 14)
            .padding(.horizontal, 16)
            .frame(maxWidth: .infinity)
            .background(Color.white)
            .cornerRadius(10)
            .overlay(
                RoundedRectangle(cornerRadius: 10)
                    .stroke(AppColors.border, lineWidth: 2)
            )
        }
    }
}
