//
//  PaymentDetails.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-07.
//

import SwiftUI

struct PaymentDetailRow: Identifiable {
    let id = UUID()
    let label: String
    let value: String
}

struct PaymentDetails: View {
    let rows: [PaymentDetailRow]

    var body: some View {
        VStack(alignment: .leading, spacing: 20) {

            Text("Payment Details")
                .font(.system(size: 20, weight: .bold))

            ForEach(rows) { row in
                HStack {
                    Text(row.label)
                        .font(.system(size: 18))
                        .foregroundColor(
                            row.label.lowercased() == "total"
                            ? .black
                            : AppColors.lableColor
                        )

                    Spacer()

                    Text(row.value)
                        .font(.app(size: .xl, weight: .bold))
                        .foregroundColor(.black)
                }
                .frame(maxWidth: .infinity)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}
