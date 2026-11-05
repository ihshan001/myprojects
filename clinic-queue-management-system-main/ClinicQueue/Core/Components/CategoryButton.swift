//
//  CateogryButton.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-02-09.
//
import SwiftUI


struct CategoryButton: View {
    var title: String
    var icon: String
    var iconWidth: CGFloat = 32
    var iconHeight: CGFloat = 32
    var buttonSize: CGFloat = 50
    var action: () -> Void
    var width: CGFloat = 65
    var textColor:Color = AppColors.lableColor

    var body: some View {
        Button(action: action) {
            VStack(spacing: 4) {
                Image(icon)
                    .resizable()
                    .aspectRatio(contentMode: .fit)
                    .frame(width: iconWidth, height: iconHeight)
                    .padding(16)
                    .background(Color.white)
                    .cornerRadius(10)
                    .shadow(color: .gray.opacity(0.4), radius: 8, x: 0, y: 7)

                Text(title)
                    .font(.caption)
                    .foregroundColor(textColor)
                    .padding(.top, 8)
                    .frame(width: iconWidth + 50, alignment: .center)
            }
            .frame(width: buttonSize)
        }
    }
}
