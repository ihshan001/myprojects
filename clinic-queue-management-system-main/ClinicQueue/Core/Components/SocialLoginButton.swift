//
//  SocialLoginButton.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-05.
//

import SwiftUI

struct SocialLoginButton: View {
    var iconName: String
    var iconColor: Color = .black
    var text: String
    var action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 12) {

                Spacer()

                Image(systemName: iconName)
                    .font(.system(size: 20))
                    .foregroundColor(iconColor)

                Text(text)
                    .font(.system(size: 16, weight: .medium))
                    .foregroundColor(.black)

                Spacer()

            }
            .padding(.horizontal, 24)
            .padding(.vertical, 14)
            .background(
                RoundedRectangle(cornerRadius: 100)
                    .stroke(Color.gray.opacity(0.25), lineWidth: 1)
            )
        }
    }
}
