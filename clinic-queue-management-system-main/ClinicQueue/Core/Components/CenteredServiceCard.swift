//
//  Untitled.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-05.
//
import SwiftUI

struct CenteredServiceCard: View {
    let service: Service
    var isHighlighted: Bool = false

    var body: some View {
        ZStack {
            RoundedRectangle(cornerRadius: 24, style: .continuous)
                .fill(service.background)
                .shadow(color: Color.black.opacity(0.05),
                        radius: 10, x: 0, y: 4)
                .overlay(
                    RoundedRectangle(cornerRadius: 24, style: .continuous)
                        .stroke(isHighlighted ? Color.purple : Color.clear,
                                lineWidth: 3)
                )

            VStack(alignment: .center, spacing: 8) {
         
                Image(systemName: service.icon)
                    .font(.system(size: 24))
                    .foregroundColor(.black)
                    .frame(width: 44, height: 44)
                    .background(
                        RoundedRectangle(cornerRadius: 12, style: .continuous)
                            .fill(Color.white.opacity(0.8))
                            .overlay(
                                RoundedRectangle(cornerRadius: 12, style: .continuous)
                                    .stroke(isHighlighted ? Color.purple.opacity(0.6)
                                                          : Color.black.opacity(0.06),
                                            lineWidth: isHighlighted ? 2 : 1)
                            )
                    )

         
                Text(service.title)
                    .font(.system(size: 16, weight: .bold))
                    .foregroundColor(AppColors.textdark) 
                    .lineLimit(1)
                    .minimumScaleFactor(0.8)
                    .multilineTextAlignment(.center)

          
                Text(service.subtitle)
                    .font(.caption)
                    .foregroundColor(.secondary)
                    .lineLimit(2)
                    .minimumScaleFactor(0.8)
                    .multilineTextAlignment(.center)
                    .fixedSize(horizontal: false, vertical: true)
                    .padding(.horizontal, 4)
            }
            .padding(12)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .clipped()
    }
}
