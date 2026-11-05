//
//  CustomModal.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-07.
//

import SwiftUI

struct CustomModal<Content: View>: View {
    @Binding var isPresented: Bool
    let content: () -> Content
    
    var body: some View {
        if isPresented {
            ZStack {
                Color.black.opacity(0.2)
                    .edgesIgnoringSafeArea(.all)
                    .onTapGesture {

                        withAnimation {
                            isPresented = false
                        }
                    }


                ZStack(alignment: .topTrailing) {
                    VStack {
                        content()
                    }
                    .padding()
                    .background(Color.white)
                    .cornerRadius(20)
                    .shadow(radius: 10)
                    .frame(maxWidth: 350)

          
                    Button(action: {
                        withAnimation {
                            isPresented = false
                        }
                    }) {
                        Image(systemName: "xmark")
                            .foregroundColor(.black)
                            .frame(width: 30, height: 30)
                            .background(Color.white)
                            .clipShape(Circle())
                            .shadow(radius: 3)
                    }
                    .offset(x: -10, y: -10) 
                }
            }
            .transition(.opacity.combined(with: .scale))
            .animation(.easeInOut, value: isPresented)
        }
    }
}
