//
//  CategoryBoxGrid.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-08.
//

import SwiftUI

struct CategoryBoxGrid: View {
    
    let item: CategoryItem
    let isSelected: Bool
    let action: () -> Void
    
    private let boxWidth: CGFloat = 63
    private let boxHeight: CGFloat = 70
    
    var body: some View {
        VStack(spacing: 6) {
            
            ZStack {
                RoundedRectangle(cornerRadius: 10)
                    .fill(isSelected ? AppColors.categoryHighlight : Color.white)
                    .shadow(color: Color.black.opacity(0.08), radius: 4)
                
                Image(item.icon)
                    .resizable()
                    .scaledToFit()
                    .frame(width: 32, height: 32)
            }
            .frame(width: boxWidth, height: 53)
            
            // Text below the icon
            Text(item.title)
                .font(.system(size: 12))
                .foregroundColor(isSelected ? AppColors.primary : AppColors.text)
                .multilineTextAlignment(.center)
                .lineLimit(2)
                .frame(maxWidth: boxWidth)
        }
        .frame(width: boxWidth, height: boxHeight)
        .onTapGesture {
            action()
        }
    }
}
