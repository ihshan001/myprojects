//
//  InputField.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-02-08.
//

import SwiftUI

struct InputField: View {

    var placeholder: String
    var placeholderColor: Color = AppColors.placeholder
    var backgroundColor: Color = .clear
    var textColor: Color = AppColors.text
    var borderRadius: CGFloat = 100
    var borderWidth: CGFloat = 1
    var borderColor: Color = AppColors.border
    var padding: CGFloat = 12

    @Binding var value: String
    @FocusState private var isFocused: Bool

    var body: some View {
        ZStack(alignment: .leading) {
            if value.isEmpty {
                Text(placeholder)
                    .foregroundColor(placeholderColor)
                    .padding(.leading, padding)
            }
            TextField("", text: $value)
                .padding(padding)
                .background(backgroundColor)
                .foregroundColor(textColor)
                .focused($isFocused)
                .overlay(
                    RoundedRectangle(cornerRadius: borderRadius)
                        .stroke(isFocused ? AppColors.primary : borderColor, lineWidth: borderWidth)
                        .animation(.easeInOut(duration: 0.2), value: isFocused)
                )
        }
    }
}
