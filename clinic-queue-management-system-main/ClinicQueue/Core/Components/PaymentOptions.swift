//
//  PaymentOptions.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-07.
//

import SwiftUI


struct PaymentOptions: View {
    let items: [CheckboxItem]
    @Binding var selectedKey: String?
    
    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Payment Option")
                .font(.system(size: 20, weight: .bold))
            
            ForEach(items) { item in
                Button {
                    selectedKey = item.key
                } label: {
                    HStack {

                        HStack(spacing: 8) {
                            if let icon = item.icon {
                                icon
                                    .resizable()
                                    .frame(width: 32, height: 32)
                            }
                            Text(item.label)
                                .foregroundColor(selectedKey == item.key ? .white : .black)
                                .font(.system(size: 16, weight: selectedKey == item.key ? .semibold : .regular))
                        }
                        .frame(minWidth: 120, alignment: .leading)
                        
                        Spacer()
                        
                        ZStack {
                            Circle()
                                .stroke(selectedKey == item.key ? Color(hex: "E6F0F0") : Color.black, lineWidth: 2)
                                .frame(width: 18, height: 18)
                            
                           
                            if selectedKey == item.key {
                                Image("Checked") 
                                    .resizable()
                                    .scaledToFit()
                                    .frame(width: 12, height: 12)
                                    .padding(0)
                            }
                        }
                    }
                    .padding(.vertical, 12)
                    .padding(.horizontal, 20)
                    .frame(maxWidth: .infinity)
                    .background(selectedKey == item.key ? Color(hex: "169A8F") : Color.white)
                    .cornerRadius(10)
                    .overlay(
                        RoundedRectangle(cornerRadius: 10)
                            .stroke(selectedKey == item.key ? Color(hex: "157979") : Color(hex: "EDF6F5"), lineWidth: 2)
                    )
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}
