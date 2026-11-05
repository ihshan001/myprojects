//
//  ActiveQueueBanner.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-10.
//

import SwiftUI

struct ActiveQueueBanner: View {
    var title: String
    var description: String
    var queueNumber: String
    var progress: Int
    var onNavTap: () -> Void

    private let themeTeal = Color(red: 0.28, green: 0.58, blue: 0.53)

    var body: some View {
        VStack(alignment: .trailing, spacing: 0) {
            HStack(alignment: .center, spacing: 20) {

                ZStack {
                    Circle()
                        .stroke(themeTeal.opacity(0.15), lineWidth: 8)
                    
                    Circle()
                        .trim(from: 0, to: CGFloat(progress) / 100.0)
                        .stroke(themeTeal, style: StrokeStyle(lineWidth: 8, lineCap: .round))
                        .rotationEffect(.degrees(-90))
                    
 
                    Text(queueNumber)
                        .font(.system(size: 16, weight: .semibold))
                        .foregroundColor(.secondary)
                }
                .frame(width: 70, height: 70)

                VStack(alignment: .leading, spacing: 4) {
                    Text(title)
                        .font(.title3)
                        .multilineTextAlignment(.leading)
                        .fontWeight(.bold)
                    
                    Text(description)
                        .font(.subheadline)
                        .foregroundColor(.secondary)
                        .multilineTextAlignment(.leading)
                        .fixedSize(horizontal: false, vertical: true)
                }

                Spacer()
            }
            .padding(.top, 20)
            .padding(.horizontal, 20)
            
            Button(action: onNavTap) {
                Text("View")
                    .font(.headline)
                    .foregroundColor(themeTeal)
                    .padding(.bottom, 20)
                    .padding(.trailing, 25)
            }
        }
        .background(themeTeal.opacity(0.05))
        .cornerRadius(20)
        .padding(.horizontal)
    }
}

