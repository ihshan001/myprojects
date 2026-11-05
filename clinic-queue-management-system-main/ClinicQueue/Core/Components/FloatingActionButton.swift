//
//  FloatingActionButton.swift.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-05.
//

import SwiftUI

struct FloatingActionButton: View {
    let icon: String
    let color: Color
    let action: () -> Void
    
    init(
        icon: String = "plus",
        color: Color = .green,
        action: @escaping () -> Void = {}
    ) {
        self.icon = icon
        self.color = color
        self.action = action
    }
    
    var body: some View {
        VStack {
            Spacer()
            HStack {
                Spacer()
                Button(action: action) {
                    Image(systemName: icon)
                        .font(.title2.weight(.bold))
                        .foregroundColor(.white)
                        .frame(width: 56, height: 56)
                        .background(color)
                        .clipShape(Circle())
                        .shadow(color: .black.opacity(0.2),
                                radius: 8, x: 0, y: 4)
                }
                .padding(.trailing, 20)
                .padding(.bottom, 20)
            }
        }
    }
}
