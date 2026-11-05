//
//  MyCartView.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-12.
//

import SwiftUI



struct CartItem: Identifiable {
    let id = UUID()
    let name: String
    let description: String
    let price: Double
    let imageName: String
    var quantity: Int
}



struct QuantityStepper: View {
    @Binding var quantity: Int

    var body: some View {
        HStack(spacing: 0) {
            Button {
                if quantity > 1 { quantity -= 1 }
            } label: {
                Text("−")
                    .font(.system(size: 18, weight: .medium))
                    .foregroundColor(.white)
                    .frame(width: 34, height: 36)
            }

            Text(String(format: "%02d", quantity))
                .font(.system(size: 14, weight: .bold))
                .foregroundColor(.white)
                .frame(width: 36, height: 36)

            Button {
                quantity += 1
            } label: {
                Text("+")
                    .font(.system(size: 18, weight: .medium))
                    .foregroundColor(.white)
                    .frame(width: 34, height: 36)
            }
        }
        .background(Color(hex: "1A2E44"))
        .cornerRadius(10)
    }
}



struct CartItemRow: View {
    @Binding var item: CartItem

    var body: some View {
        HStack(spacing: 14) {
            Image(item.imageName)
                .resizable()
                .scaledToFit()
                .frame(width: 72, height: 72)
                .clipShape(RoundedRectangle(cornerRadius: 10))

            VStack(alignment: .leading, spacing: 4) {
                Text(item.name)
                    .font(.system(size: 15, weight: .bold))
                    .foregroundColor(Color(hex: "1A2E44"))

                Text(item.description)
                    .font(.system(size: 12))
                    .foregroundColor(.gray)
                    .lineLimit(2)

                HStack {
                    Text(String(format: "$%05.2f", item.price))
                        .font(.system(size: 14, weight: .bold))
                        .foregroundColor(Color(hex: "1A2E44"))

                    Spacer()

                    QuantityStepper(quantity: $item.quantity)
                }
                .padding(.top, 4)
            }
        }
        .padding(16)
        .background(Color.white)
        .cornerRadius(16)
        .shadow(color: Color.black.opacity(0.05), radius: 8, x: 0, y: 3)
    }
}



struct PrescriptionFileRow: View {
    let image: UIImage?
    let fileName: String

    var body: some View {
        HStack(spacing: 12) {
            if let img = image {
                Image(uiImage: img)
                    .resizable()
                    .scaledToFill()
                    .frame(width: 48, height: 48)
                    .clipShape(RoundedRectangle(cornerRadius: 8))
            } else {
                RoundedRectangle(cornerRadius: 8)
                    .fill(Color(.systemGray5))
                    .frame(width: 48, height: 48)
                    .overlay(
                        Image(systemName: "doc.fill")
                            .foregroundColor(.gray)
                    )
            }

            Text(fileName)
                .font(.system(size: 13, weight: .medium))
                .foregroundColor(Color(hex: "1A2E44"))
                .lineLimit(1)
                .truncationMode(.middle)

            Spacer()
        }
        .padding(12)
        .background(Color.white)
        .cornerRadius(12)
        .overlay(
            RoundedRectangle(cornerRadius: 12)
                .stroke(Color(.systemGray4), lineWidth: 1)
        )
    }
}


struct MyCartView: View {
    let prescriptionImage: UIImage?

    @State private var cartItems: [CartItem] = [
        CartItem(
            name: "Lipitor Atorvastatin",
            description: "Lorem ipsum dolor sit amet, consectetur adipiscing elit",
            price: 10.00,
            imageName: "Lipitor",
            quantity: 1
        ),
        CartItem(
            name: "levothyroxine",
            description: "Lorem ipsum dolor sit amet, consectetur adipiscing elit",
            price: 10.00,
            imageName: "levothyroxine",
            quantity: 1
        ),
        CartItem(
            name: "Ibuprofen",
            description: "Lorem ipsum dolor sit amet, consectetur adipiscing elit",
            price: 10.00,
            imageName: "Ibuprofen",
            quantity: 1
        )
    ]

    @Environment(\.dismiss) private var dismiss

    var prescriptionFileName: String {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyyMMddHHmm"
        return "screenshot\(formatter.string(from: Date())).....JPG"
    }

    var totalItems: Int {
        cartItems.reduce(0) { $0 + $1.quantity }
    }

    var body: some View {
        ZStack(alignment: .bottom) {
            Color(.systemBackground).ignoresSafeArea()

            ScrollView(showsIndicators: false) {
                VStack(alignment: .leading, spacing: 20) {

                    VStack(alignment: .leading, spacing: 12) {
                        Text("Prescription")
                            .font(.system(size: 18, weight: .bold))
                            .foregroundColor(Color(hex: "1A2E44"))

                        PrescriptionFileRow(
                            image: prescriptionImage,
                            fileName: prescriptionFileName
                        )
                    }
                    .padding(.horizontal, 16)
                    .padding(.top, 16)

                    VStack(spacing: 14) {
                        ForEach(cartItems.indices, id: \.self) { index in
                            CartItemRow(item: $cartItems[index])
                        }
                    }
                    .padding(.horizontal, 16)

                    Spacer().frame(height: 90)
                }
            }

            VStack(spacing: 0) {
                NavigationLink(destination: PharPayment()) {
                    Text("Check Out")
                        .font(.system(size: 17, weight: .bold))
                        .foregroundColor(.white)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 18)
                        .background(AppColors.primary)
                        .cornerRadius(30)
                }
                .padding(.horizontal, 40)
                .padding(.vertical, 16)
            }
            .background(
                Color.white
                    .shadow(color: Color.black.opacity(0.06), radius: 12, x: 0, y: -4)
                    .ignoresSafeArea()
            )
        }
        .navigationTitle("My Cart")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .navigationBarLeading) {
                Button { dismiss() } label: {
                    Image(systemName: "chevron.left")
                        .font(.system(size: 16, weight: .semibold))
                        .foregroundColor(Color(hex: "1A2E44"))
                }
            }
            ToolbarItem(placement: .navigationBarTrailing) {
                ZStack(alignment: .topTrailing) {
                    Image(systemName: "cart")
                        .font(.system(size: 18, weight: .semibold))
                        .foregroundColor(Color(hex: "1A2E44"))
                    if totalItems > 0 {
                        Text("\(totalItems)")
                            .font(.system(size: 10, weight: .bold))
                            .foregroundColor(.white)
                            .frame(width: 16, height: 16)
                            .background(AppColors.primary)
                            .clipShape(Circle())
                            .offset(x: 4, y: -1)
                    }
                }
            }
        }
        .navigationBarBackButtonHidden(true)
    }
}



#Preview {
    NavigationStack {
        MyCartView(prescriptionImage: nil)
    }
}
