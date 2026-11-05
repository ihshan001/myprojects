//
//  CashRefundSheet.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-13.
//

import SwiftUI
import CoreImage.CIFilterBuiltins
import Combine

struct CashRefundSheet: View {
    @Binding var isPresented: Bool
    let onDismiss: () -> Void

    @State private var secondsLeft = 300
    @State private var refundCode = "REFUND-\(Int.random(in: 100000...999999))"

    private let context = CIContext()
    private let filter = CIFilter.qrCodeGenerator()

    var body: some View {
        ZStack {
            Color.black.opacity(0.5)
                .ignoresSafeArea()

            VStack(spacing: 20) {

                Image(systemName: "arrow.uturn.backward.circle.fill")
                    .font(.system(size: 50))
                    .foregroundColor(.orange)

                Text("Refund Initiated")
                    .font(.system(size: 20, weight: .bold))

                Text("Please go to the payment counter and present this QR code to collect your refund amount.")
                    .font(.system(size: 14))
                    .foregroundColor(.secondary)
                    .multilineTextAlignment(.center)

                if let qrImage = generateQRCode() {
                    Image(uiImage: qrImage)
                        .interpolation(.none)
                        .resizable()
                        .scaledToFit()
                        .frame(width: 180, height: 180)
                        .padding(.vertical, 4)
                }

                Text(refundCode)
                    .font(.system(size: 12, design: .monospaced))
                    .foregroundColor(.gray)

                Text("Window closes in \(formattedCountdown)")
                    .font(.system(size: 12))
                    .foregroundColor(.gray)

                Button("Close") {
                    dismiss()
                }
                .foregroundColor(.red)
                .font(.system(size: 15, weight: .medium))
                .padding(.top, 4)
            }
            .padding(24)
            .background(Color.white)
            .cornerRadius(20)
            .shadow(radius: 10)
            .padding(32)
        }
        .onReceive(Timer.publish(every: 1, on: .main, in: .common).autoconnect()) { _ in
            guard isPresented else { return }
            if secondsLeft > 0 {
                secondsLeft -= 1
            } else {
                dismiss()
            }
        }
    }

    private var formattedCountdown: String {
        let m = secondsLeft / 60
        let s = secondsLeft % 60
        return String(format: "%d:%02d", m, s)
    }

    private func dismiss() {
        isPresented = false
        onDismiss()
    }

    private func generateQRCode() -> UIImage? {
        let data = Data(refundCode.utf8)
        filter.setValue(data, forKey: "inputMessage")
        filter.setValue("H", forKey: "inputCorrectionLevel")
        if let output = filter.outputImage {
            let scaled = output.transformed(by: CGAffineTransform(scaleX: 10, y: 10))
            if let cgImg = context.createCGImage(scaled, from: scaled.extent) {
                return UIImage(cgImage: cgImg)
            }
        }
        return nil
    }
}
