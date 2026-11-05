//
//  PrescriptionUploadModal.swift
//  ClinicQueue
//
//  Created by Roshan on 2026-03-12.
//

import SwiftUI
import PhotosUI

struct PrescriptionUploadModal: View {
    @Binding var isPresented: Bool
    var onNext: (UIImage?) -> Void         

    @State private var selectedImage: UIImage? = nil
    @State private var isPickerPresented = false
    @State private var photosPickerItem: PhotosPickerItem? = nil

    var body: some View {
        ZStack {

            Color.black.opacity(0.35)
                .ignoresSafeArea()
                .onTapGesture {
                    withAnimation(.spring()) { isPresented = false }
                }

  
            VStack(spacing: 0) {

                ZStack {
                    RoundedRectangle(cornerRadius: 16)
                        .fill(Color(.systemGray6))
                        .overlay(
                            RoundedRectangle(cornerRadius: 16)
                                .strokeBorder(
                                    Color(.systemGray4),
                                    style: StrokeStyle(lineWidth: 1.5, dash: [6])
                                )
                        )
                        .frame(height: 170)

                    if let image = selectedImage {
                        ZStack(alignment: .topTrailing) {
                            Image(uiImage: image)
                                .resizable()
                                .scaledToFill()
                                .frame(height: 170)
                                .clipShape(RoundedRectangle(cornerRadius: 16))

                            Button {
                                withAnimation { selectedImage = nil }
                            } label: {
                                Image(systemName: "xmark.circle.fill")
                                    .font(.system(size: 22))
                                    .foregroundColor(.white)
                                    .shadow(radius: 4)
                                    .padding(8)
                            }
                        }
                    } else {
                        VStack(spacing: 10) {
                            ZStack {
                                Circle()
                                    .fill(Color(.systemGray5))
                                    .frame(width: 48, height: 48)
                                Image(systemName: "arrow.up.circle")
                                    .font(.system(size: 26, weight: .medium))
                                    .foregroundColor(Color(hex: "1A2E44"))
                            }

                            Text("Drag & drop files here")
                                .font(.system(size: 15, weight: .semibold))
                                .foregroundColor(Color(hex: "1A2E44"))

                            Text("or Select Browse")
                                .font(.system(size: 13))
                                .foregroundColor(.gray)
                        }
                    }
                }
                .padding(.horizontal, 20)
                .padding(.top, 24)
                .onTapGesture { isPickerPresented = true }

                HStack(spacing: 14) {
                    Button {
                        withAnimation(.spring(response: 0.3, dampingFraction: 0.8)) {
                            isPresented = false
                        }
                    } label: {
                        Text("Cancel")
                            .font(.system(size: 15, weight: .semibold))
                            .foregroundColor(Color(hex: "1A2E44"))
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 14)
                            .background(Color.white)
                            .cornerRadius(30)
                            .overlay(
                                RoundedRectangle(cornerRadius: 30)
                                    .stroke(Color(.systemGray4), lineWidth: 1.5)
                            )
                    }

                    Button {
                        isPresented = false
                       
                        DispatchQueue.main.asyncAfter(deadline: .now() + 0.35) {
                            onNext(selectedImage)
                        }
                    } label: {
                        Text("Next")
                            .font(.system(size: 15, weight: .semibold))
                            .foregroundColor(.white)
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 14)
                            .background(AppColors.primary)
                            .cornerRadius(30)
                    }
                }
                .padding(.horizontal, 20)
                .padding(.top, 20)
                .padding(.bottom, 24)
            }
            .background(Color.white)
            .cornerRadius(24)
            .shadow(color: Color.black.opacity(0.15), radius: 30, x: 0, y: 10)
            .padding(.horizontal, 28)
            .transition(.scale(scale: 0.92).combined(with: .opacity))
        }
        .photosPicker(isPresented: $isPickerPresented, selection: $photosPickerItem, matching: .images)
        .onChange(of: photosPickerItem) { newItem in
            Task {
                if let data = try? await newItem?.loadTransferable(type: Data.self),
                   let uiImage = UIImage(data: data) {
                    withAnimation { selectedImage = uiImage }
                }
            }
        }
    }
}


#Preview {
    PrescriptionUploadModalPreviewWrapper()
}

private struct PrescriptionUploadModalPreviewWrapper: View {
    @State private var isPresented = true

    var body: some View {
        NavigationView {
            ZStack {
                Color(.systemGroupedBackground).ignoresSafeArea()

                Text("Pharmacy Screen")
                    .font(.system(size: 18, weight: .bold))
                    .foregroundColor(.gray.opacity(0.4))

                if isPresented {
                    PrescriptionUploadModal(isPresented: $isPresented) { _ in }
                } else {
                    Button("Show Modal Again") {
                        isPresented = true
                    }
                    .font(.system(size: 15, weight: .semibold))
                    .foregroundColor(.white)
                    .padding(.horizontal, 24)
                    .padding(.vertical, 12)
                    .background(Color(hex: "0DC8A4"))
                    .cornerRadius(24)
                }
            }
        }
    }
}
