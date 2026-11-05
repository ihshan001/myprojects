//
//  DatePickerField.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-13.
//

import SwiftUI

struct DatePickerField: View {
    var placeholder: String
    @Binding var selection: Date
    
    var borderRadius: CGFloat = 100
    var borderWidth: CGFloat = 1
    var borderColor: Color = AppColors.border
    var padding: CGFloat = 12

    var body: some View {
        HStack {
            Image(systemName: "calendar")
                .foregroundColor(.gray)
                .padding(.leading, padding)
            
            DatePicker(
                "",
                selection: $selection,
                displayedComponents: [.date]
            )
            .labelsHidden()
            .accentColor(AppColors.primary)
            
            Spacer()
        }
        .frame(height: 50)
        .overlay(
            RoundedRectangle(cornerRadius: borderRadius)
                .stroke(borderColor, lineWidth: borderWidth)
        )
    }
}
