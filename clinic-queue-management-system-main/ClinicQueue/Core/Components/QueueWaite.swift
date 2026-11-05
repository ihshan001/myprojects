//
//  QueueWaite.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-09.
//

import SwiftUI
import CoreImage.CIFilterBuiltins


struct QRCodeView: View {
    var content: String
    var size: CGFloat = 200
    
    var qrImage: UIImage {
        let context = CIContext()
        let filter = CIFilter.qrCodeGenerator()
        filter.message = Data(content.utf8)
        filter.correctionLevel = "H"
        
        if let outputImage = filter.outputImage {
            let scaled = outputImage.transformed(by: CGAffineTransform(scaleX: 10, y: 10))
            if let cgImage = context.createCGImage(scaled, from: scaled.extent) {
                return UIImage(cgImage: cgImage)
            }
        }
        return UIImage()
    }
    
    var body: some View {
        Image(uiImage: qrImage)
            .interpolation(.none)
            .resizable()
            .scaledToFit()
            .frame(width: size, height: size)
            .padding(12)
            .background(Color.white)
            .cornerRadius(16)
    }
}


struct QueueWaite: View {
    
    var queueNumber: String = "11"
    var nowServing: String = "05"
    var estimatedWait: String = "~15 minutes"
    var qrContent: String = "CLINIC-QUEUE-11" 
    
    var body: some View {
        ZStack {
            
            Image("welcome_bg")
                .resizable()
                .scaledToFill()
                .frame(height: 600)
                .frame(width: 450)
            
            VStack(spacing: 32) {
                
                Spacer()
                
            
                VStack(spacing: 16) {
                    Text("It's your turn")
                        .font(.system(size: 22, weight: .semibold))
                        .foregroundColor(.white)
                    
                    Text(queueNumber)
                        .font(.system(size: 48, weight: .bold))
                        .foregroundColor(.white)
                        .frame(width: 120, height: 120)
                        .background(Color.black)
                        .cornerRadius(20)
                }
                
              
                VStack(spacing: 12) {
                    Text("Show this QR code at the counter")
                        .font(.system(size: 18, weight: .semibold))
                        .foregroundColor(.white)
                    
                    QRCodeView(content: qrContent, size: 100)
                }
                
                Spacer()
                Spacer()
                Spacer()
            }
            .padding(.horizontal, 24)
        }
    }
}

#Preview {
    QueueWaite()
}
