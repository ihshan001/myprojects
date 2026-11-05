//
//  LabCategoryButton.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-08.
//

import SwiftUI

struct LabCategoryButton: View {
    let title: String
    let icon: String
    let iconWidth: CGFloat
    var backgroundColor: Color = .clear
    var foregroundColor: Color = .primary
    let action: () -> Void
    
    var body: some View {
        Button(action: action) {
            VStack(spacing: 4) {
                Image(icon)
                    .resizable()
                    .scaledToFit()
                    .frame(width: iconWidth, height: iconWidth)
                
                Text(title)
                    .font(.caption)
                    .multilineTextAlignment(.center)
                    .foregroundColor(foregroundColor)
                    .fixedSize(horizontal: false, vertical: true)
            }
            .padding(.vertical, 8)
            .padding(.horizontal, 4)
            .frame(maxWidth: .infinity)
            .background(backgroundColor)
            .cornerRadius(8)
        }
    }
}

#Preview {
    VStack {
        LabCategoryButton(title: "General Physician", icon: "SearchIcon", iconWidth: 38) {
            print("Tapped")
        }
        
        LabCategoryButton(
            title: "Cardiologist",
            icon: "SearchIcon",
            iconWidth: 38,
            backgroundColor: .green,
            foregroundColor: .white
        ) {
            print("Tapped")
        }
    }
    .padding()
}
