//
//  RoundCheckboxGroup.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-07.
//

import SwiftUI

struct RoundCheckboxGroup: View {

    let items: [CheckboxItem]
    @Binding var selectedKeys: Set<String>

    var body: some View {
        VStack(spacing: 12) {
            ForEach(items) { item in
                let isSelected = selectedKeys.contains(item.key)

                Button {
                    toggleSelection(item.key)
                } label: {
                    HStack {
                        Text(item.label)
                            .foregroundColor(.black)
                            .font(.system(size: 16))
                        
                        Spacer()
                        
                        ZStack {
                            if isSelected {
                            
                                Image("checkIcon2")
                                    .resizable()
                                    .frame(width: 20, height: 20)
                            } else {
                              
                                Circle()
                                    .stroke(Color.black, lineWidth: 1)
                                    .frame(width: 18, height: 18)
                            }
                        }
                    }
                    .padding(12)
                    .frame(maxWidth: .infinity)
                    .background(
                        isSelected
                        ? Color(red: 205/255, green: 249/255, blue: 246/255)
                        : Color(red: 244/255, green: 247/255, blue: 247/255)
                    )
                    .cornerRadius(10)
                }
                .buttonStyle(.plain)
            }
        }
    }

    private func toggleSelection(_ key: String) {
        if selectedKeys.contains(key) {
            selectedKeys.remove(key)
        } else {
            selectedKeys.insert(key)
        }
    }
}
