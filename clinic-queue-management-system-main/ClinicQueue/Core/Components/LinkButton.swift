//
//  LinkButton.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-08.
//

import SwiftUI

struct LinkButton: View {
    var title: String
    var textColor: Color = AppColors.primary
    var action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(title)
                .foregroundColor(textColor)
                .font(.app(.button))
        }
    }
}
