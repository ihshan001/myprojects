//
//  PrescriptionBanner.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-12.
//

import SwiftUI

struct PrescriptionBanner: View {
    @State private var showUploadModal = false
    @State private var navigateToCart = false          
    @State private var prescriptionImage: UIImage? = nil

    var body: some View {
        ZStack {
          
            NavigationLink(
                destination: MyCartView(prescriptionImage: prescriptionImage),
                isActive: $navigateToCart
            ) { EmptyView() }

            HStack(spacing: 0) {
                VStack(alignment: .leading, spacing: 12) {
                    Text("Order quickly with\nprescription")
                        .font(.system(size: 17, weight: .bold))
                        .foregroundColor(Color(hex: "1A2E44"))
                        .lineSpacing(3)

                    Button {
                        withAnimation(.spring(response: 0.35, dampingFraction: 0.8)) {
                            showUploadModal = true
                        }
                    } label: {
                        Text("Upload Prescription")
                            .font(.system(size: 14, weight: .semibold))
                            .foregroundColor(.white)
                            .padding(.horizontal, 18)
                            .padding(.vertical, 10)
                            .background(AppColors.primary)
                            .cornerRadius(25)
                    }
                }
                .padding(.leading, 20)
                .padding(.vertical, 22)

                Spacer()

                ZStack {
                    Circle()
                        .fill(Color(hex: "0DC8A4").opacity(0.15))
                        .frame(width: 90, height: 90)
                        .offset(x: 10, y: -10)

                    VStack(spacing: -8) {
                        Image(systemName: "cross.fill")
                            .font(.system(size: 24, weight: .bold))
                            .foregroundColor(.red)
                            .offset(x: 10, y: 0)

                        Image(systemName: "doc.text.fill")
                            .font(.system(size: 42))
                            .foregroundColor(Color(hex: "C8E6FF"))

                        Image(systemName: "pills.fill")
                            .font(.system(size: 22))
                            .foregroundColor(Color(hex: "FFD700"))
                            .offset(x: -5, y: -8)
                    }
                }
                .padding(.trailing, 10)
            }
            .background(
                LinearGradient(
                    colors: [Color(hex: "E6F9F5"), Color(hex: "D0F0FF")],
                    startPoint: .topLeading,
                    endPoint: .bottomTrailing
                )
            )
            .cornerRadius(18)
            .shadow(color: Color(hex: "0DC8A4").opacity(0.1), radius: 10, x: 0, y: 4)
        }
        .fullScreenCover(isPresented: $showUploadModal) {
            PrescriptionUploadModal(
                isPresented: $showUploadModal,
                onNext: { image in
                    prescriptionImage = image
                    navigateToCart = true
                }
            )
            .background(BackgroundClearView())
        }
    }
}
struct BackgroundClearView: UIViewRepresentable {
    func makeUIView(context: Context) -> UIView {
        let view = UIView()
        DispatchQueue.main.async {
            view.superview?.superview?.backgroundColor = .clear
        }
        return view
    }
    func updateUIView(_ uiView: UIView, context: Context) {}
}
