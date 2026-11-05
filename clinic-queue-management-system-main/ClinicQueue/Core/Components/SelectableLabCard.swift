//
//  SelectableLabCard.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-06.
//

import SwiftUI

struct SelectableLabCard: View {
    let props: LabCardData
    let isSelected: Bool
    let onTap: () -> Void
    let onButtonTap: () -> Void

    private let brandColor = Color(red: 0.28, green: 0.58, blue: 0.53)

    var body: some View {
        HStack(alignment: .center, spacing: 20) {
            
            Image(props.icon)
                .resizable()
                .scaledToFit()
                .frame(width: 40, height: 40)
                .padding(12)
            
            VStack(alignment: .leading, spacing: 10) {
                
                // TITLE + TOGGLE RIGHT SIDE
                HStack {
                    Text(props.title)
                        .font(.app(.subheading))
                    
                    Spacer()
                    
                    Image(systemName: isSelected ? "checkmark.circle.fill" : "circle")
                        .foregroundColor(isSelected ? brandColor : .gray)
                        .font(.system(size: 24))
                        .onTapGesture {
                            onTap()
                        }
                }
                
                Text(props.description1)
                    .font(.app(.caption))
                
                HStack(spacing: 4) {
                    Text(props.label1)
                    Text(props.label1Text)
                }
                .font(.app(.caption))
                
                HStack(spacing: 4) {
                    Text(props.label2)
                    Text(props.label2Text)
                }
                .font(.app(.caption))
                
                if !props.buttonText.isEmpty {
                    PrimaryButton(
                        title: props.buttonText,
                        maxWidth: 140
                    ) {
                        onButtonTap()
                    }
                }
            }
        }
        .frame(maxWidth: .infinity)
        .padding(12)
        .background(
            RoundedRectangle(cornerRadius: 10)
                .fill(props.cardBackground)
                .overlay(
                    RoundedRectangle(cornerRadius: 10)
                        .stroke(isSelected ? brandColor : Color.clear, lineWidth: 2)
                )
        )
        .shadow(color: .gray.opacity(0.2), radius: 5, x: 0, y: 6)
    }
}


struct LabCardData: Identifiable {
    let id = UUID()
    let icon: String
    let iconSize: CGFloat
    let title: String
    let description1: String
    let label1: String
    let label1Text: String
    let label2: String
    let label2Text: String
    let buttonText: String
    var cardBackground: Color = Color(.systemBackground)
    
    init(icon: String, iconSize: CGFloat = 32, title: String, description1: String, label1: String, label1Text: String, label2: String, label2Text: String, buttonText: String) {
        self.icon = icon
        self.iconSize = iconSize
        self.title = title
        self.description1 = description1
        self.label1 = label1
        self.label1Text = label1Text
        self.label2 = label2
        self.label2Text = label2Text
        self.buttonText = buttonText
    }
}
