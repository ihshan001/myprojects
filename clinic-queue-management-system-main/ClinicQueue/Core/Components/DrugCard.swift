//
//  DrugCard.swift
//  ClinicQueue
//
//  Created by Roshan on 2026-03-12.
//

import SwiftUI

struct DrugCard: View {
    let drug: Drug
    @State private var quantity: Int = 0

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {

            // ── Image Area ───────────────────────────────────────
            ZStack {
                RoundedRectangle(cornerRadius: 12)
                    .fill(drug.color.opacity(0.12))
                    .frame(height: 110)

                Image(drug.imageName)
                    .resizable()
                    .scaledToFit()
                    .frame(width: 80, height: 80)
                    .clipShape(RoundedRectangle(cornerRadius: 8))
            }
            .padding(.bottom, 10)

            // ── Name & Subtitle ──────────────────────────────────
            Text(drug.name)
                .font(.system(size: 14, weight: .bold))
                .foregroundColor(.black)
                .lineLimit(2)

            Text(drug.subtitle)
                .font(.system(size: 12))
                .foregroundColor(.gray)
                .padding(.top, 2)

            // ── Price + Add / Stepper ────────────────────────────
            HStack {
                Text(String(format: "$%.2f", drug.price))
                    .font(.system(size: 15, weight: .bold))
                    .foregroundColor(.black)

                Spacer()

                if quantity == 0 {
                    Button {
                        withAnimation(.spring(response: 0.3, dampingFraction: 0.6)) {
                            quantity = 1
                        }
                    } label: {
                        Image(systemName: "plus")
                            .font(.system(size: 14, weight: .bold))
                            .foregroundColor(.white)
                            .frame(width: 30, height: 30)
                            .background(Color(hex: "1A2E44"))
                            .cornerRadius(6)
                    }
                } else {
                    HStack(spacing: 0) {
                        Button {
                            withAnimation(.spring(response: 0.3, dampingFraction: 0.6)) {
                                if quantity > 1 { quantity -= 1 } else { quantity = 0 }
                            }
                        } label: {
                            Text("−")
                                .font(.system(size: 18, weight: .medium))
                                .foregroundColor(.white)
                                .frame(width: 28, height: 30)
                        }

                        Text(String(format: "%02d", quantity))
                            .font(.system(size: 13, weight: .bold))
                            .foregroundColor(.white)
                            .frame(width: 28, height: 30)

                        Button {
                            withAnimation(.spring(response: 0.3, dampingFraction: 0.6)) {
                                quantity += 1
                            }
                        } label: {
                            Text("+")
                                .font(.system(size: 18, weight: .medium))
                                .foregroundColor(.white)
                                .frame(width: 28, height: 30)
                        }
                    }
                    .background(Color(hex: "1A2E44"))
                    .cornerRadius(6)
                    .transition(.scale.combined(with: .opacity))
                }
            }
            .padding(.top, 8)
        }
        .padding(12)
        .background(Color.white)
        .cornerRadius(16)
        .shadow(color: Color.black.opacity(0.06), radius: 10, x: 0, y: 4)
    }
}
