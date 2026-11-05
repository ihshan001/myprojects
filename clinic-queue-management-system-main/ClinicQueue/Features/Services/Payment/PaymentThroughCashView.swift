//
//  PaymentThroughCashView.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-08.
//

import SwiftUI
import CoreImage.CIFilterBuiltins

struct PaymentThroughCashView<SuccessDestination: View>: View {
    
    @EnvironmentObject var sessionManager: SessionManager
    
    @State private var navigateToStatus = false
    private let paymentSuccess = true
    
    private let patientName = "John Doe"
    private let billingId = "B12345"
    private let amount = "$70.00"
    
    let context = CIContext()
    let filter = CIFilter.qrCodeGenerator()
    
    // This closure provides the destination PaymentStatusView with the proper values
    let onPaymentSuccess: () -> SuccessDestination
    
    var body: some View {
        NavigationStack {
            ZStack {
                ScrollView {
                    VStack(spacing: 20) {
                        
                        Text("Pay at Counter")
                            .font(.system(size: 20, weight: .bold))
                            .frame(maxWidth: .infinity, alignment: .center)
                            .padding(.horizontal)
                        
                        Text("Please present this QR code at the payment counter to complete your payment.")
                            .foregroundColor(AppColors.lableColor)
                            .frame(maxWidth: .infinity, alignment: .center)
                            .padding(.top, 16)
                            .multilineTextAlignment(.center)
                        
                        Text("Our staff will scan the code and assist you.")
                            .foregroundColor(AppColors.lableColor)
                            .frame(maxWidth: .infinity, alignment: .center)
                            .multilineTextAlignment(.center)
                        
                        if let qrImage = generateQRCode() {
                            Image(uiImage: qrImage)
                                .interpolation(.none)
                                .resizable()
                                .scaledToFit()
                                .frame(width: 200, height: 200)
                                .padding(.top, 16)
                        }
                        
                        Text("\(patientName) | \(billingId) | \(amount)")
                            .font(.footnote)
                            .foregroundColor(.gray)
                    }
                    .padding()
                }
                
                FloatingNav(
                    mainIcon: "plus",
                    items: [
                        FloatingNavItem(icon: "house.fill", label: "Home", destination: AnyView(ServicesView())),
                        FloatingNavItem(icon: "map.fill", label: "Map", destination: AnyView(Text("Map View"))),
                        FloatingNavItem(icon: "gearshape.fill", label: "Settings", destination: AnyView(SettingsView()))
                    ]
                )
            }
            
            // NavigationLink to PaymentStatusView, activated automatically
            NavigationLink(
                destination: onPaymentSuccess(),
                isActive: $navigateToStatus,
                label: { EmptyView() }
            )
        }
        .onAppear {
            // Mimic QR scan delay: automatically navigate after 3 seconds
            DispatchQueue.main.asyncAfter(deadline: .now() + 3) {
                navigateToStatus = true
            }
        }
    }
    
    private func generateQRCode() -> UIImage? {
        let dataString = "\(patientName)|\(billingId)|\(amount)"
        let data = Data(dataString.utf8)
        filter.setValue(data, forKey: "inputMessage")
        filter.setValue("H", forKey: "inputCorrectionLevel")
        
        if let outputImage = filter.outputImage {
            let scaledImage = outputImage.transformed(by: CGAffineTransform(scaleX: 10, y: 10))
            if let cgimg = context.createCGImage(scaledImage, from: scaledImage.extent) {
                return UIImage(cgImage: cgimg)
            }
        }
        return nil
    }
}
